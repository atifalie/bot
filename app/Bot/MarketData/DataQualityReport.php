<?php

namespace App\Bot\MarketData;

readonly class DataQualityReport
{
    public function __construct(
        public bool $isValid,
        public int $totalCandles,
        public float $nullPercent,
        public int $duplicateCount,
        public int $gapCount,
        public float $largestGapMultiplier = 1.0,
        public array $issues = [],
    ) {}

    public function summary(): string
    {
        return sprintf(
            '[%s] candles=%d null%%=%.2f dup=%d gaps=%d max_gap_x=%.2f issues=%s',
            $this->isValid ? 'VALID' : 'INVALID',
            $this->totalCandles,
            $this->nullPercent,
            $this->duplicateCount,
            $this->gapCount,
            $this->largestGapMultiplier,
            $this->issues === [] ? '-' : implode(',', $this->issues),
        );
    }
}
