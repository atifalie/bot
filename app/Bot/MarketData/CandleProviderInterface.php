<?php

namespace App\Bot\MarketData;

/**
 * Contract shared by REST-backed CandleFetcher and the WS-fed
 * StreamingCandleFetcher — lets one pipeline serve both modes.
 */
interface CandleProviderInterface
{
    public function fetch(string $symbol, string $timeframe, ?int $limit = null): CandleFetchResult;
}
