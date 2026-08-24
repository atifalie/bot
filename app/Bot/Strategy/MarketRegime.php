<?php

namespace App\Bot\Strategy;

readonly class MarketRegime
{
    public function __construct(
        public string $regime,              // TRENDING, RANGING, TRANSITIONAL, UNKNOWN
        public float $adx,
        public string $trendStrength,       // STRONG, MODERATE, WEAK, NONE
        public string $volatility,          // LOW, NORMAL, HIGH, EXTREME
        public bool $bbSqueeze,
        public bool $tradeable,
        public float $confidenceAdjustment, // -30..+20
        public string $reason,
    ) {}
}
