<?php

namespace App\Bot\MarketData;

use App\Bot\Exchange\Trader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for market candles: fetch → validate → clean →
 * drop the forming candle. Retry/ban logic lives in Trader only.
 *
 * Higher-timeframes (1h+) are cached for a few minutes — they only
 * change hourly, so re-fetching every cycle is wasted API weight.
 */
class CandleFetcher implements CandleProviderInterface
{
    public function __construct(
        protected Trader $trader,
        protected CandleValidator $validator,
    ) {}

    public function fetch(string $symbol, string $timeframe, ?int $limit = null): CandleFetchResult
    {
        $limit ??= (int) Config::get('bot.market.candle_lookback', 300);

        $raw = $this->isCacheable($timeframe)
            ? $this->cachedOhlcv($symbol, $timeframe, $limit)
            : $this->trader->fetchOhlcv($symbol, $timeframe, $limit);

        $result = $this->validator->validateAndClean(
            $raw,
            $timeframe,
            (float) Config::get('bot.market.max_candle_gap_multiplier', 2.5),
            (float) Config::get('bot.validation.max_allowed_null_percent', 2.0),
        );

        $series = $result->series->dropFormingCandle($timeframe);

        if (! $result->report->isValid) {
            Log::warning("[CandleFetcher] [{$symbol} {$timeframe}] invalid data: {$result->report->summary()}");
        }

        return new CandleFetchResult($series, $result->report);
    }

    /**
     * Timeframes whose candles are slow-moving enough to share across cycles.
     */
    private function isCacheable(string $timeframe): bool
    {
        return in_array(
            $timeframe,
            (array) Config::get('bot.market.htf_cache_timeframes', ['1h', '2h', '4h', '1d']),
            true,
        );
    }

    private function cachedOhlcv(string $symbol, string $timeframe, int $limit): array
    {
        $ttl = (int) Config::get('bot.market.htf_cache_ttl_seconds', 300);

        return Cache::remember(
            "bot:candles:{$symbol}:{$timeframe}",
            now()->addSeconds($ttl),
            fn () => $this->trader->fetchOhlcv($symbol, $timeframe, $limit),
        );
    }
}
