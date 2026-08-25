<?php

namespace App\Bot\Exchange;

use ccxt\Exchange;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ExchangeManager
{
    protected const CLIENT_ORDER_ID_PARAMS = [
        'binance' => 'newClientOrderId',
        'bybit' => 'orderLinkId',
    ];

    public function __construct(protected BanGuard $banGuard) {}

    /**
     * Client-order-id parameter name differs per exchange; used to make order
     * recovery deterministic after timeouts.
     */
    public static function clientOrderIdParam(string $exchangeId): string
    {
        return self::CLIENT_ORDER_ID_PARAMS[$exchangeId] ?? 'newClientOrderId';
    }

    public function create(): Exchange
    {
        $exchangeId = (string) config('bot.exchange.id', 'binance');
        $class = '\\ccxt\\'.$exchangeId;

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Unsupported exchange: {$exchangeId}");
        }

        $options = ['defaultType' => 'spot'];

        if ($exchangeId === 'bybit') {
            // We always send base-currency quantity for market buys.
            $options['createMarketBuyOrderRequiresPrice'] = false;
            // Generous recv_window absorbs small clock drift on demo/testnet.
            $options['recvWindow'] = 15000;
            // Spot-only bot: loading demo options/inverse instruments breaks
            // load_markets with 10003 on signed fetches.
            $options['fetchMarkets'] = ['spot'];
            $options['adjustForTimeDifference'] = true;
        }

        /** @var Exchange $exchange */
        $exchange = new $class([
            'apiKey' => (string) config('bot.exchange.api_key'),
            'secret' => (string) config('bot.exchange.api_secret'),
            'enableRateLimit' => true,
            'timeout' => (int) config('bot.exchange.request_timeout_ms', 15000),
            'options' => $options,
        ]);

        // Environment switch MUST happen before load_markets, otherwise the
        // request hits mainnet and demo keys fail with 10003 (invalid key).
        if ($exchangeId === 'bybit' && config('bot.exchange.use_demo')) {
            $exchange->enable_demo_trading(true);
            Log::info('[ExchangeManager] DEMO mode ON (bybit) — fake funds, real prices.');
        } elseif (config('bot.exchange.use_testnet')) {
            $exchange->set_sandbox_mode(true);
            Log::info("[ExchangeManager] Testnet mode ON ({$exchangeId}).");
        } else {
            Log::warning("[ExchangeManager] LIVE mode ON ({$exchangeId}) — real funds at risk.");
        }

        $this->loadMarketsWithRetry($exchange);

        // Sanity clamp: adjustForTimeDifference boot pe EK dafa calibrate hota
        // hai aur phir cached rehta hai. Agar calibration ke waqt network
        // hiccup thi to offset din bhar ke liye poison ho jata (28-din case).
        // Host clock NTP se synced hai — 1 min se bara offset matlab galat
        // calibration hai, local clock trust karo.
        $diff = (int) ($exchange->options['timeDifference'] ?? 0);
        if (abs($diff) > 60_000) {
            Log::warning(sprintf(
                '[ExchangeManager] implausible timeDifference %+.1fmin — resetting to 0 (local clock trusted)',
                $diff / 60_000,
            ));
            $exchange->options['timeDifference'] = 0;
        }

        return $exchange;
    }

    protected function loadMarketsWithRetry(Exchange $exchange, int $attempts = 5): void
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $exchange->load_markets();
                Log::info('[ExchangeManager] load_markets OK: '.count($exchange->markets ?: []).' markets');

                return;
            } catch (\Throwable $e) {
                Log::warning("[ExchangeManager] load_markets failed (attempt {$attempt}/{$attempts}): {$e->getMessage()}");

                if ($attempt < $attempts) {
                    sleep($attempt * 2);
                }
            }
        }
    }
}
