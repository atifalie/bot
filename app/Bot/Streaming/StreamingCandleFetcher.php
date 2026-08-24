<?php

namespace App\Bot\Streaming;

use App\Bot\MarketData\CandleFetchResult;
use App\Bot\MarketData\CandleProviderInterface;
use App\Bot\MarketData\CandleValidator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Drop-in replacement for CandleFetcher that reads live candles from the
 * WS-fed CandleBuffer instead of hitting REST on every cycle.
 *
 * Same fetch() contract → the whole decision pipeline works unchanged.
 * Data still passes through CandleValidator so quality gates stay active.
 */
class StreamingCandleFetcher implements CandleProviderInterface
{
    public function __construct(
        protected CandleBuffer $buffer,
        protected CandleValidator $validator,
    ) {}

    public function fetch(string $symbol, string $timeframe, ?int $limit = null): CandleFetchResult
    {
        $limit ??= (int) Config::get('bot.market.candle_lookback', 300);

        $raw = $this->buffer->ohlcv($symbol, $timeframe);
        $raw = array_slice($raw, -$limit);

        if ($raw === []) {
            Log::warning("[StreamingCandleFetcher] [{$symbol} {$timeframe}] buffer empty — not seeded yet");

            // Empty dataset → validator marks it invalid, pipeline skips safely.
            return $this->validator->validateAndClean([], $timeframe,
                (float) Config::get('bot.market.max_candle_gap_multiplier', 2.5),
                (float) Config::get('bot.validation.max_allowed_null_percent', 2.0),
            );
        }

        $result = $this->validator->validateAndClean(
            $raw,
            $timeframe,
            (float) Config::get('bot.market.max_candle_gap_multiplier', 2.5),
            (float) Config::get('bot.validation.max_allowed_null_percent', 2.0),
        );

        // WS feed already delivers only confirmed candles — no forming drop needed,
        // but keep the call for identical semantics with REST mode.
        $series = $result->series->dropFormingCandle($timeframe);

        return new CandleFetchResult($series, $result->report);
    }
}
