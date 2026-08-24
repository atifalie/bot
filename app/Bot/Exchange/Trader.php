<?php

namespace App\Bot\Exchange;

use App\Bot\Exchange\Exceptions\ExchangeBannedException;
use App\Bot\Exchange\Exceptions\OrderPlacementException;
use ccxt\BaseError;
use ccxt\Exchange;
use ccxt\NetworkError;
use ccxt\RequestTimeout;
use Illuminate\Support\Facades\Log;
use Throwable;

class Trader
{
    public function __construct(
        protected Exchange $exchange,
        protected BanGuard $banGuard,
    ) {}

    public function exchange(): Exchange
    {
        return $this->exchange;
    }

    public function ensureMarkets(): bool
    {
        if ($this->exchange->markets) {
            return true;
        }

        try {
            $this->exchange->load_markets();

            return (bool) $this->exchange->markets;
        } catch (Throwable $e) {
            Log::warning("[Trader] ensure_markets failed: {$e->getMessage()}");

            return false;
        }
    }

    public function fetchOhlcv(string $symbol, string $timeframe, int $limit): array
    {
        return $this->withRetry('fetch_ohlcv', fn () => $this->exchange->fetch_ohlcv($symbol, $timeframe, null, $limit));
    }

    /**
     * Drop symbols the exchange doesn't list — one dead coin should never
     * waste a cycle (or crash it). Returns only valid ones.
     */
    public function validateSymbols(array $symbols): array
    {
        $valid = [];
        foreach ($symbols as $symbol) {
            if (! empty($this->exchange->markets[$symbol])) {
                $valid[] = $symbol;
            } else {
                Log::warning("[Trader] [{$symbol}] not on {$this->exchange->id} spot — removed from this cycle");
            }
        }

        return $valid;
    }

    /** Paginated history fetch for backtesting (ccxt handles since → pages). */
    public function fetchOhlcvSince(string $symbol, string $timeframe, int $sinceMs, int $limit = 1000): array
    {
        return $this->withRetry('fetch_ohlcv', fn () => $this->exchange->fetch_ohlcv($symbol, $timeframe, $sinceMs, $limit));
    }

    public function getUsdtBalance(): float
    {
        $balance = $this->withRetry('fetch_balance', fn () => $this->exchange->fetch_balance());
        $usdt = $balance['USDT'] ?? 0;

        if (is_array($usdt)) {
            return (float) ($usdt['free'] ?? 0) + (float) ($usdt['used'] ?? 0);
        }

        return (float) ($usdt ?: 0);
    }

    /**
     * Non-zero spot asset balances keyed by asset code (e.g. ['BTC' => 0.001]).
     * Used by reconciliation to detect bags the local state doesn't know about.
     *
     * @return array<string, float>
     */
    public function getAssetBalances(): array
    {
        $balance = $this->withRetry('fetch_balance', fn () => $this->exchange->fetch_balance());
        $out = [];

        foreach (($balance['total'] ?? []) as $asset => $total) {
            if ($asset === 'USDT' || (float) $total <= 0) {
                continue;
            }
            $out[(string) $asset] = (float) $total;
        }

        return $out;
    }

    public function getCurrentPrice(string $symbol): float
    {
        $ticker = $this->withRetry('fetch_ticker', fn () => $this->exchange->fetch_ticker($symbol));

        return (float) $ticker['last'];
    }

    /**
     * No automatic retry on order placement — a failed order must surface to
     * the caller immediately, blind retries risk duplicate positions.
     */
    public function placeMarketBuy(string $symbol, float $quantity, ?string $clientId = null): array
    {
        return $this->placeMarketOrder($symbol, 'buy', $quantity, $clientId, 'BUY');
    }

    public function placeMarketSell(string $symbol, float $quantity, ?string $clientId = null): array
    {
        return $this->placeMarketOrder($symbol, 'sell', $quantity, $clientId, 'SELL');
    }

    /**
     * Exchange-side stop-loss sell. Bybit uses limit+triggerPrice; Binance the
     * native STOP_LOSS_LIMIT type. Prices/qty are rounded via market filters.
     * Returns null on failure — the caller decides whether that is fatal.
     */
    public function placeStopLoss(string $symbol, float $quantity, float $stopPrice): ?array
    {
        $this->ensureMarkets();

        try {
            [$stopPrice, $quantity] = $this->applyPrecision($symbol, $stopPrice, $quantity);
            $limitPrice = $stopPrice * 0.98;

            Log::info(sprintf('[Trader] Placing SL: %.8f %s trigger=%.8f limit=%.8f', $quantity, $symbol, $stopPrice, $limitPrice));

            $order = $this->exchange->id === 'bybit'
                ? $this->exchange->create_order($symbol, 'limit', 'sell', $quantity, $limitPrice, ['triggerPrice' => $stopPrice])
                : $this->exchange->create_order($symbol, 'STOP_LOSS_LIMIT', 'SELL', $quantity, $limitPrice, ['triggerPrice' => $stopPrice]);

            Log::info("[Trader] SL order placed: id={$order['id']}");

            return $order;
        } catch (BaseError $e) {
            Log::warning("[Trader] SL order failed [{$symbol}]: {$e->getMessage()}");

            return null;
        }
    }

    public function placeTakeProfit(string $symbol, float $quantity, float $tpPrice): ?array
    {
        try {
            $limitPrice = $tpPrice * 0.99;

            Log::info(sprintf('[Trader] Placing TP: %.8f %s trigger=%.8f limit=%.8f', $quantity, $symbol, $tpPrice, $limitPrice));

            $order = $this->exchange->id === 'bybit'
                ? $this->exchange->create_order($symbol, 'limit', 'sell', $quantity, $limitPrice, ['triggerPrice' => $tpPrice])
                : $this->exchange->create_order($symbol, 'TAKE_PROFIT_LIMIT', 'SELL', $quantity, $limitPrice, ['triggerPrice' => $tpPrice]);

            Log::info("[Trader] TP order placed: id={$order['id']}");

            return $order;
        } catch (BaseError $e) {
            Log::warning("[Trader] TP order failed [{$symbol}]: {$e->getMessage()}");

            return null;
        }
    }

    public function cancelOpenOrders(string $symbol): int
    {
        try {
            $orders = $this->exchange->fetch_open_orders($symbol);

            foreach ($orders as $order) {
                try {
                    $this->exchange->cancel_order($order['id'], $symbol);
                    Log::info("[Trader] Cancelled order {$order['id']} {$order['side']} {$order['type']}");
                } catch (Throwable $e) {
                    Log::warning("[Trader] Failed to cancel order {$order['id']}: {$e->getMessage()}");
                }
            }

            return count($orders);
        } catch (Throwable $e) {
            Log::warning("[Trader] Failed to fetch open orders for {$symbol}: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Free (sellable) balance of a coin; null means the fetch failed and the
     * caller should decide how to proceed.
     */
    public function getFreeBalance(string $coin): ?float
    {
        try {
            $balance = $this->exchange->fetch_balance();
            $info = $balance[$coin] ?? 0;

            if (is_array($info)) {
                return (float) ($info['free'] ?? 0);
            }

            return (float) ($info ?: 0);
        } catch (Throwable $e) {
            Log::warning("[Trader] get_free_balance({$coin}) failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * After a timeout or lost response this resolves ambiguity by searching
     * open + recent closed orders for our client id.
     */
    public function findOrderByClientId(string $symbol, ?string $clientId): ?array
    {
        if (! $clientId) {
            return null;
        }

        try {
            foreach ($this->exchange->fetch_open_orders($symbol) as $order) {
                if ($this->extractClientId($order) === $clientId) {
                    return $order;
                }
            }

            $closed = [];

            try {
                $closed = $this->exchange->fetch_closed_orders($symbol, null, 50);
            } catch (Throwable) {
            }

            foreach ($closed as $order) {
                if ($this->extractClientId($order) === $clientId) {
                    return $order;
                }
            }
        } catch (Throwable $e) {
            Log::warning("[Trader] findOrderByClientId({$symbol}, {$clientId}) failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Fee-dust-safe exit: clamps quantity to actual free balance, applies
     * exchange precision, then market-sells.
     *
     * @return array{order: ?array, sold_qty: float}
     */
    public function sellBasePosition(string $symbol, float $quantity, ?string $clientId = null): array
    {
        $base = explode('/', $symbol)[0];
        $free = $this->getFreeBalance($base);
        $sellQty = $free === null ? $quantity : min($quantity, $free);

        if ($sellQty <= 0) {
            Log::warning("[Trader] [{$symbol}] Nothing to sell: free=".var_export($free, true).", state_qty={$quantity}");

            return ['order' => null, 'sold_qty' => 0.0];
        }

        try {
            $sellQty = (float) $this->exchange->amount_to_precision($symbol, $sellQty);
        } catch (Throwable) {
        }

        if ($sellQty <= 0) {
            Log::warning("[Trader] [{$symbol}] Sell qty rounded to 0 by precision — nothing to sell");

            return ['order' => null, 'sold_qty' => 0.0];
        }

        $order = $this->placeMarketSell($symbol, $sellQty, $clientId);

        return ['order' => $order, 'sold_qty' => $sellQty];
    }

    protected function placeMarketOrder(string $symbol, string $side, float $quantity, ?string $clientId, string $label): array
    {
        $this->ensureMarkets();

        try {
            $params = $clientId ? [ExchangeManager::clientOrderIdParam($this->exchange->id) => $clientId] : [];
            Log::info(sprintf('[Trader] Placing %s: %.8f %s cid=%s', $label, $quantity, $symbol, $clientId ?? '-'));

            $order = $side === 'buy'
                ? $this->exchange->create_market_buy_order($symbol, $quantity, $params)
                : $this->exchange->create_market_sell_order($symbol, $quantity, $params);

            if (($order['status'] ?? null) === 'closed' || (float) ($order['filled'] ?? 0) > 0) {
                return $order;
            }

            usleep(500_000);

            // Bybit restricts fetchOrder() to recent orders; prefer the
            // dedicated endpoints (market orders normally close instantly).
            $orderId = $order['id'];
            $refetched = null;

            foreach (['fetch_closed_order', 'fetch_open_order'] as $method) {
                try {
                    $refetched = $this->exchange->{$method}($orderId, $symbol);

                    break;
                } catch (Throwable) {
                }
            }

            $order = $refetched
                ?? $this->exchange->fetch_order($orderId, $symbol, ['acknowledged' => true]);

            if (($order['status'] ?? null) !== 'closed') {
                Log::warning("[Trader] {$label} order not filled: status={$order['status']}, id={$order['id']}");
            }

            return $order;
        } catch (BaseError $e) {
            throw new OrderPlacementException("{$label} order failed: {$e->getMessage()}", $e);
        }
    }

    /**
     * Retries only genuine network hiccups with linear backoff. Rate-limit
     * hits mark a global ban and rethrow immediately; deterministic errors
     * (bad symbol, auth) never retry.
     */
    protected function withRetry(string $operation, callable $fn): mixed
    {
        $maxRetries = (int) config('bot.exchange.max_retries', 3);
        $backoffSec = (float) config('bot.exchange.retry_backoff_seconds', 2.0);
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($this->banGuard->isBanned()) {
                throw new ExchangeBannedException($operation, $this->banGuard->bannedUntilMs());
            }

            try {
                $result = $fn();
                $this->banGuard->trackWeightHeader($this->exchange->last_response_headers ?? null);

                return $result;
            } catch (Throwable $e) {
                if ($this->banGuard->isRateLimitError($e)) {
                    $this->banGuard->markBanFromException($e);
                    Log::error("[Trader] {$operation}: rate limit/IP ban — not retrying");
                    throw $e;
                }

                if ($e instanceof NetworkError || $e instanceof RequestTimeout) {
                    $lastError = $e;
                    Log::warning("[Trader] {$operation} network issue, attempt {$attempt}/{$maxRetries}: {$e->getMessage()}");

                    if ($attempt < $maxRetries) {
                        usleep((int) ($backoffSec * $attempt * 1_000_000));
                    }

                    continue;
                }

                throw $e;
            }
        }

        throw $lastError ?? new \RuntimeException("{$operation} failed after {$maxRetries} attempts");
    }

    /** @return array{0: float, 1: float} */
    protected function applyPrecision(string $symbol, float $price, float $quantity): array
    {
        try {
            return [
                (float) $this->exchange->price_to_precision($symbol, $price),
                (float) $this->exchange->amount_to_precision($symbol, $quantity),
            ];
        } catch (Throwable $e) {
            Log::warning("[Trader] [{$symbol}] precision rounding failed: {$e->getMessage()}");

            return [$price, $quantity];
        }
    }

    protected function extractClientId(array $order): ?string
    {
        return $order['clientOrderId']
            ?? ($order['info']['clientOrderId'] ?? ($order['info']['orderLinkId'] ?? null));
    }
}
