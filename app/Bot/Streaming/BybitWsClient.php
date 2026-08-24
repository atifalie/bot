<?php

namespace App\Bot\Streaming;

use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector;
use Throwable;

/**
 * Bybit v5 public spot WebSocket client (kline streams).
 *
 * - Subscribes kline.{tf}.{SYMBOL} in chunks of <=10 args per message
 * - Application-level ping every N seconds (Bybit drops silent conns)
 * - Auto-reconnect with backoff; onReconnect hook lets the owner re-seed
 * - Normalizes kline payloads to ccxt ohlcv tuples and pushes into CandleBuffer
 */
class BybitWsClient
{
    protected ?WebSocket $conn = null;

    /** @var callable|null */
    public $onReady = null;

    protected int $attempts = 0;

    public int $messagesReceived = 0;

    public bool $shuttingDown = false;

    /** @var array<int, string> pending subscribe topics for next connect */
    protected array $topics = [];

    public function __construct(
        protected string $url = 'wss://stream.bybit.com/v5/public/spot',
        protected CandleBuffer $buffer = new CandleBuffer,
        protected int $pingIntervalSeconds = 18,
        protected int $maxBackoffSeconds = 60,
    ) {}

    /**
     * @param  array<string>  $symbolPairs  e.g. ['BTC/USDT', 'ETH/USDT']
     * @param  array<string>  $timeframes  e.g. ['5', '15'] (Bybit interval codes)
     */
    public function buildTopics(array $symbolPairs, array $timeframes): void
    {
        $topics = [];
        foreach ($timeframes as $tf) {
            foreach ($symbolPairs as $pair) {
                $topics[] = 'kline.'.$tf.'.'.str_replace('/', '', $pair);
            }
        }
        $this->topics = $topics;
    }

    public function connect(LoopInterface $loop): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $connector = new Connector($loop, new SocketConnector($loop));

        $connector($this->url)->then(
            function (WebSocket $conn) use ($loop) {
                Log::info('[Ws] connected: '.$this->url);
                $this->conn = $conn;
                $this->attempts = 0;
                $this->attachHandlers($conn, $loop);
                $this->subscribeAll();
                $this->startPingTimer($loop);
                if ($this->onReady) {
                    ($this->onReady)();
                }
            },
            function (Throwable $e) use ($loop) {
                Log::error('[Ws] connect failed: '.$e->getMessage());
                $this->scheduleReconnect($loop);
            }
        );
    }

    protected function subscribeAll(): void
    {
        // Bybit allows max 10 args per subscribe message
        foreach (array_chunk($this->topics, 10) as $chunk) {
            $this->send(['op' => 'subscribe', 'args' => $chunk]);
        }
    }

    protected function startPingTimer(LoopInterface $loop): void
    {
        static $timer = null;
        if ($timer !== null) {
            $loop->cancelTimer($timer);
        }

        $timer = $loop->addPeriodicTimer($this->pingIntervalSeconds, function () {
            $this->send(['op' => 'ping']);
        });
    }

    public function handleRaw(string $payload): void
    {
        $this->messagesReceived++;
        if ($this->messagesReceived <= 3) {
            Log::info('[Ws] first payloads: '.substr($payload, 0, 200));
        }

        $msg = json_decode($payload, true);
        if (! is_array($msg)) {
            return;
        }

        $op = $msg['op'] ?? null;
        if ($op === 'ping') {
            $this->send(['op' => 'pong']);

            return;
        }

        if ($op === 'subscribe') {
            $success = (bool) ($msg['success'] ?? false);
            if (! $success) {
                Log::warning('[Ws] subscribe rejected: '.json_encode($msg));
            }

            return;
        }

        if (($msg['ret_msg'] ?? '') === 'OK' || isset($msg['conn_id'])) {
            return; // welcome/pong frames
        }

        $topic = $msg['topic'] ?? '';
        if (str_starts_with((string) $topic, 'kline.') && isset($msg['data'])) {
            $this->handleKlines((string) $topic, (array) $msg['data']);
        }
    }

    /**
     * Bybit v5 kline rows arrive as objects:
     * {start, end, interval, open, close, high, low, volume, turnover, confirm, timestamp}
     * (defensive: also accept legacy positional arrays)
     */
    protected function handleKlines(string $topic, array $rows): void
    {
        // topic: kline.{interval}.{SYMBOL}
        $parts = explode('.', $topic, 3);
        if (count($parts) < 3) {
            return;
        }
        [, $interval, $exchangeSymbol] = $parts;
        $symbol = $this->toPairSymbol($exchangeSymbol);
        $tf = $this->normalizeInterval($interval);

        foreach ($rows as $row) {
            if (is_array($row) && array_is_list($row)) {
                if (count($row) < 6) {
                    continue;
                }
                $candle = [
                    (int) $row[0],
                    (float) $row[1],
                    (float) $row[2],
                    (float) $row[3],
                    (float) $row[4],
                    (float) $row[5],
                ];
                $confirmed = (bool) ($row[6] ?? false);
            } else {
                $row = (array) $row;
                if (! isset($row['start'], $row['close'])) {
                    continue;
                }
                $candle = [
                    (int) $row['start'],
                    (float) $row['open'],
                    (float) $row['high'],
                    (float) $row['low'],
                    (float) $row['close'],
                    (float) ($row['volume'] ?? 0),
                ];
                $confirmed = (bool) ($row['confirm'] ?? false);
            }

            $this->buffer->upsert($symbol, $tf, $candle, $confirmed);
        }
    }

    protected function toPairSymbol(string $exchangeSymbol): string
    {
        // BTCUSDT → BTC/USDT (quote is always USDT in this system)
        if (str_ends_with($exchangeSymbol, 'USDT')) {
            return substr($exchangeSymbol, 0, -4).'/USDT';
        }

        return $exchangeSymbol;
    }

    /**
     * Map Bybit interval codes to the timeframes used across this codebase.
     */
    protected function normalizeInterval(string $code): string
    {
        return match ($code) {
            '1' => '1m',
            '3' => '3m',
            '5' => '5m',
            '15' => '15m',
            '30' => '30m',
            '60' => '1h',
            '120' => '2h',
            '240' => '4h',
            'D' => '1d',
            default => $code,
        };
    }

    public function send(array $data): void
    {
        if ($this->conn === null) {
            return;
        }

        try {
            $this->conn->send(json_encode($data));
        } catch (Throwable $e) {
            Log::warning('[Ws] send failed: '.$e->getMessage());
        }
    }

    public function scheduleReconnect(LoopInterface $loop): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->attempts++;
        $delay = min($this->maxBackoffSeconds, 2 ** min($this->attempts, 6));
        Log::warning("[Ws] reconnecting in {$delay}s (attempt {$this->attempts})");

        $loop->addTimer($delay, function () use ($loop) {
            $this->connect($loop);
        });
    }

    public function onClose(callable $handler): void
    {
        $this->closeHandler = $handler;
    }

    /** @var callable|null */
    protected $closeHandler = null;

    public function close(): void
    {
        $this->shuttingDown = true;
        $this->conn?->close();
        $this->conn = null;
    }

    /**
     * Wire standard message/close/error handlers onto a fresh connection.
     */
    public function attachHandlers(WebSocket $conn, LoopInterface $loop): void
    {
        $conn->on('message', function (MessageInterface $msg) {
            try {
                $this->handleRaw((string) $msg);
            } catch (Throwable $e) {
                Log::error('[Ws] message handling failed: '.$e->getMessage().' @ '.substr((string) $msg, 0, 120));
            }
        });

        $conn->on('close', function () use ($loop) {
            Log::warning('[Ws] connection closed');
            $this->conn = null;
            if ($this->closeHandler) {
                ($this->closeHandler)();
            }
            $this->scheduleReconnect($loop);
        });
    }
}
