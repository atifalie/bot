<?php

namespace App\Console\Commands;

use App\Bot\Exchange\Trader;
use App\Bot\MarketData\CandleValidator;
use App\Bot\Monitoring\Heartbeat;
use App\Bot\Risk\PortfolioCap;
use App\Bot\Streaming\BybitWsClient;
use App\Bot\Streaming\CandleBuffer;
use App\Bot\Streaming\StreamingCandleFetcher;
use App\Bot\Trading\PaperTrader;
use App\Bot\Trading\StateStore;
use App\Bot\Trading\SymbolCycleProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;

/**
 * Streaming daemon — replaces per-minute REST polling with a persistent
 * Bybit WebSocket (kline streams).
 *
 * Flow:
 *  1. REST-seed candle history once (indicators need lookback)
 *  2. Subscribe kline.{LTF,HTF}.{SYMBOL} for every active symbol
 *  3. On each CONFIRMED LTF candle close → run the same decision pipeline
 *     as bot:run (via SymbolCycleProcessor + StreamingCandleFetcher)
 *
 * Orders/balance stay on REST (low frequency). If the daemon dies,
 * start.sh's restart loop revives it. Fallback mode: bot:run (REST).
 */
class BotDaemon extends Command
{
    protected $signature = 'bot:daemon
        {--paper : Run in paper trading mode}
        {--balance= : Starting paper balance (default 10000)}';

    protected $description = 'WebSocket-streaming trading daemon (kline events drive decisions)';

    /** @var array<string, int> last processed confirmed LTF candle ts per symbol */
    protected array $lastProcessedTs = [];

    public function handle(
        Trader $trader,
        SymbolCycleProcessor $processor,
    ): int {
        $paperMode = $this->option('paper');
        $paperBalance = (float) ($this->option('balance') ?? 10000);

        $timeframe = Config::get('bot.market.timeframe', '5m');
        $htf = Config::get('bot.market.higher_timeframe', '15m');

        $this->info(sprintf(
            '🤖 Bot v5 [STREAM] starting (profile=%s) %s tf=%s htf=%s',
            Config::get('bot.profile'),
            $paperMode ? '[PAPER]' : '[LIVE]',
            $timeframe,
            $htf,
        ));

        // Symbols (same path as bot:run)
        $symbols = Config::get('bot.market.symbols', ['BTC/USDT', 'ETH/USDT']);
        $before = count($symbols);
        $symbols = $trader->validateSymbols($symbols);
        if (count($symbols) < $before) {
            $this->warn('⚠️ '.($before - count($symbols)).' invalid symbol(s) skipped — baaki '.count($symbols).' active');
        }
        $this->info('Active symbols ('.count($symbols).'): '.implode(', ', $symbols));

        // Paper trader init
        $paperTrader = $paperMode ? new PaperTrader($paperBalance) : null;

        // CRITICAL: WS client aur daemon ko SAME buffer share karni hai —
        // bina singleton ke container har jagah alag instance inject karta.
        app()->singleton(CandleBuffer::class);

        // WS client + shared buffer
        $buffer = app(CandleBuffer::class);
        $ws = app(BybitWsClient::class);
        $ws->buildTopics($symbols, [$this->toBybitInterval($timeframe), $this->toBybitInterval($htf)]);

        $fetcher = new StreamingCandleFetcher($buffer, app(CandleValidator::class));
        $usdtBalance = 0.0;

        // REST seed — blocking, before loop starts.
        // IMPORTANT: forming (incomplete) candle ko hatao warna uska ts pehle
        // hi "processed" ban jata hai aur confirm hone par detection miss hoti hai.
        $this->info('🌱 Seeding candle history via REST...');
        foreach ($symbols as $symbol) {
            try {
                foreach ([$timeframe, $htf] as $tf) {
                    $raw = $trader->fetchOhlcv($symbol, $tf, (int) Config::get('bot.market.candle_lookback', 300));
                    $buffer->seed($symbol, $tf, $this->dropForming($raw, $tf));
                }
                $lastTs = $buffer->ohlcv($symbol, $timeframe);
                $this->lastProcessedTs[$symbol] = $lastTs === [] ? 0 : $lastTs[array_key_last($lastTs)][0];
                $this->line("  ✓ {$symbol}");
            } catch (\Throwable $e) {
                $this->warn("  ✗ {$symbol}: {$e->getMessage()} — REST fallback iske liye fetch karega jab tak seed na ho");
            }
            usleep(250_000); // gentle pacing
        }

        Heartbeat::logStartup();
        Log::info('[Daemon] streaming started with '.count($symbols).' symbols');
        $this->info('🌱 Seeding complete — connecting WebSocket...');

        $loop = Loop::get();

        // WS lifecycle
        $ws->onReady = function () use ($trader, $buffer, $symbols, $timeframe, $htf) {
            // After every successful (re)connect, repair gaps via fresh REST seed
            foreach ($symbols as $symbol) {
                try {
                    foreach ([$timeframe, $htf] as $tf) {
                        $raw = $trader->fetchOhlcv($symbol, $tf, (int) Config::get('bot.market.candle_lookback', 300));
                        $buffer->seed($symbol, $tf, $this->dropForming($raw, $tf));
                    }
                } catch (\Throwable $e) {
                    Log::warning("[Daemon] reseed failed for {$symbol}: {$e->getMessage()}");
                }
            }
            Log::info('[Daemon] buffers ready — live processing ON');
        };
        $ws->connect($loop);
        $this->info('🔌 WS connect initiated — event loop running (Ctrl+C to stop)');

        // Decision driver: poll buffer each second; process symbol when its
        // LTF candle closes (new confirmed ts appears).
        $scanSignal = storage_path('app/scan_now.signal');
        $loop->addPeriodicTimer(1.0, function () use ($processor, $fetcher, $buffer, $symbols, $timeframe, $paperMode, $paperTrader, $trader, &$usdtBalance, $scanSignal) {
            // SCAN NOW: dashboard signal file → force re-process all symbols
            if (file_exists($scanSignal)) {
                @unlink($scanSignal);
                $this->lastProcessedTs = [];
                Log::info('[Daemon] Scan signal received — force-processing all symbols');
            }

            foreach ($symbols as $symbol) {
                try {
                    $series = $buffer->ohlcv($symbol, $timeframe);
                    if ($series === []) {
                        continue; // not seeded yet
                    }
                    $lastTs = $series[array_key_last($series)][0];
                    if ($lastTs <= ($this->lastProcessedTs[$symbol] ?? 0)) {
                        continue; // nothing new
                    }

                    // Refresh balance at most once per second-window across symbols
                    if (! $paperMode) {
                        static $lastBalanceFetch = 0;
                        if (time() - $lastBalanceFetch > 60) {
                            $usdtBalance = $trader->getUsdtBalance();
                            $lastBalanceFetch = time();
                            Cache::put('dash_usdt_balance', $usdtBalance, 120);
                        }
                    } else {
                        $usdtBalance = $paperTrader?->getSummary()['balance'] ?? 10000;
                        Cache::put('dash_usdt_balance', $usdtBalance, 120);
                    }

                    // Dashboard ke liye live price cache karo — render() ko Bybit API call nahi karni padegi
                    $closePrice = $series[array_key_last($series)][4] ?? null;
                    if ($closePrice !== null) {
                        Cache::put('dash_price_'.str_replace('/', '_', $symbol), (float) $closePrice, 120);
                    }

                    $this->lastProcessedTs[$symbol] = $lastTs;

                    // Portfolio cap full → flat coins ki entry-search skip
                    // (open positions ke exits/trailing phir bhi process honge)
                    $canOpenNew = PortfolioCap::canOpenNew(
                        app(StateStore::class),
                        $symbols,
                    );

                    $processor->process(
                        symbol: $symbol,
                        candleFetcher: $fetcher,
                        paperMode: $paperMode,
                        paperTrader: $paperTrader,
                        usdtBalance: (float) $usdtBalance,
                        canOpenNew: $canOpenNew,
                    );
                } catch (\Throwable $e) {
                    Log::error("[Daemon] [{$symbol}] skipped: {$e->getMessage()}");
                }
            }
        });

        // Heartbeat timer — same JSON file as REST mode (dashboard reads it)
        $cycleCount = 0;
        $loop->addPeriodicTimer(60.0, function () use (&$cycleCount, $symbols, &$usdtBalance, $paperMode, $paperTrader, $buffer, $timeframe, $ws) {
            $cycleCount++;

            // GRACEFUL STOP: dashboard ka Stop button BOT_STOP flag lagata hai.
            // Daemon khud exit karta hai — wrapper phir flag dekh ke respawn
            // NAHI karega. (nobody-user PHP root processes kill nahi kar sakta,
            // is liye signal-file pattern use hota hai.)
            if (is_file(storage_path('app/BOT_STOP'))) {
                Log::info('[Daemon] BOT_STOP flag detected — shutting down gracefully');
                Heartbeat::update(
                    cycleCount: $cycleCount,
                    activeSymbols: [],
                    openPositions: [],
                    balance: 0.0,
                    status: 'stopped',
                );
                exit(0);
            }

            try {
                // DATA STALENESS WATCHDOG: WS silently dead ho to decisions
                // chupchap ruk jati hain.
                // Age CLOSE-time se naapi jati hai (start se NAHI — 1h TF pe
                // har hour-boundary pe start-age 120min tak jayaz hoti hai,
                // 90min threshold false-positive crash-loop karta tha).
                // close-age > 90min = do consecutive confirms miss = dead.
                static $lastAgeLog = 0;
                $newest = 0;
                foreach ($symbols as $s) {
                    $ser = $buffer->ohlcv($s, $timeframe);
                    if ($ser !== []) {
                        $newest = max($newest, (int) $ser[array_key_last($ser)][0]);
                    }
                }
                $closeAgeMs = $newest > 0
                    ? ((int) (microtime(true) * 1000)) - ($newest + $this->intervalMs($timeframe))
                    : 0;
                if ($newest > 0 && $closeAgeMs > 5_400_000) {
                    Log::error('[Daemon] candle data stale (closed '.round($closeAgeMs / 60_000).'min ago) — restarting daemon for fresh seed+WS');
                    exit(1);
                }
                if ($cycleCount % 5 === 0 && time() - $lastAgeLog > 240) {
                    $lastAgeLog = time();
                    Log::info('[Daemon] health: ws_msgs='.$ws->messagesReceived.' last_candle_closed='.round(max(0, $closeAgeMs) / 60_000).'min ago');
                }

                if (! $paperMode) {
                    $usdtBalance = app(Trader::class)->getUsdtBalance();
                } else {
                    $usdtBalance = $paperTrader?->getSummary()['balance'] ?? 10000;
                }
                Heartbeat::update(
                    cycleCount: $cycleCount,
                    activeSymbols: $symbols,
                    openPositions: array_keys(array_filter(array_map(function ($s) {
                        return app(StateStore::class)->loadPosition($s)['in_position'] ? $s : null;
                    }, $symbols))),
                    balance: (float) $usdtBalance,
                    status: 'streaming',
                );
            } catch (\Throwable $e) {
                Log::warning('[Daemon] heartbeat failed: '.$e->getMessage());

                // Bybit 10002 = clock-skew rejection. ccxt ka calibrated
                // timeDifference poison ho sakta hai (galat boot-time sync) —
                // resync karo, warna har signed call (orders/balance) fail hoti rahegi.
                if (str_contains($e->getMessage(), '10002') || str_contains($e->getMessage(), 'recv_window')) {
                    try {
                        $diff = $trader->resyncServerTime();
                        Log::info('[Daemon] server-time resynced, offset now '.round($diff / 1000, 1).'s');
                    } catch (\Throwable $e2) {
                        Log::warning('[Daemon] time resync failed: '.$e2->getMessage());
                    }
                }
            }
        });

        // SELF-HEALING repair timer: agar boot ke waqt DB/REST down tha to
        // buffers khali reh sakti hain — har 3 min unseeded symbols ko dobara seed karo.
        $lookback = (int) Config::get('bot.market.candle_lookback', 300);
        $loop->addPeriodicTimer(180.0, function () use ($trader, $buffer, $symbols, $timeframe, $htf, $lookback) {
            foreach ($symbols as $symbol) {
                try {
                    foreach ([$timeframe, $htf] as $tf) {
                        if (! $buffer->isSeeded($symbol, $tf)) {
                            $raw = $trader->fetchOhlcv($symbol, $tf, $lookback);
                            $buffer->seed($symbol, $tf, $this->dropForming($raw, $tf));
                            Log::info("[Daemon] repaired seed: {$symbol} {$tf}");
                        }
                    }
                    $series = $buffer->ohlcv($symbol, $timeframe);
                    $lastTs = $series === [] ? 0 : $series[array_key_last($series)][0];
                    if (($this->lastProcessedTs[$symbol] ?? 0) < $lastTs) {
                        $this->lastProcessedTs[$symbol] = $lastTs;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[Daemon] repair seed pending for {$symbol}: {$e->getMessage()}");
                }
            }
        });

        $loop->run();

        Heartbeat::logShutdown('daemon_exit');

        return Command::SUCCESS;
    }

    /**
     * '5m' → '5', '1h' → '60', '1d' → 'D'
     */
    protected function toBybitInterval(string $tf): string
    {
        return match ($tf) {
            '1m' => '1',
            '3m' => '3',
            '5m' => '5',
            '15m' => '15',
            '30m' => '30',
            '1h' => '60',
            '2h' => '120',
            '4h' => '240',
            '1d' => 'D',
            default => $tf,
        };
    }

    protected function intervalMs(string $tf): int
    {
        return match ($tf) {
            '1m' => 60_000,
            '3m' => 180_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '30m' => 1_800_000,
            '1h' => 3_600_000,
            '2h' => 7_200_000,
            '4h' => 14_400_000,
            '1d' => 86_400_000,
            default => 300_000,
        };
    }

    /**
     * REST history ka aakhri candle agar abhi form ho raha ho (close-time
     * future mein) use hata do — sirf confirmed candles seed honi chahiye.
     */
    protected function dropForming(array $ohlcv, string $timeframe): array
    {
        if ($ohlcv === []) {
            return $ohlcv;
        }

        $last = $ohlcv[array_key_last($ohlcv)];
        $closesAt = $last[0] + $this->intervalMs($timeframe);

        if ($closesAt > (now()->timestamp * 1000)) {
            array_pop($ohlcv);
        }

        return $ohlcv;
    }
}
