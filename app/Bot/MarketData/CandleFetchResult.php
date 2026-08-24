<?php

namespace App\Bot\MarketData;

readonly class CandleFetchResult
{
    public function __construct(
        public CandleSeries $series,
        public DataQualityReport $report,
    ) {}
}
