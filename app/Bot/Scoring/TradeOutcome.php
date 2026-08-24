<?php

namespace App\Bot\Scoring;

readonly class TradeOutcome
{
    public function __construct(
        public string $direction,      // "BUY" or "SELL"
        public float $confidence,
        public bool $won,
        public float $pnlPercent,
        public string $timestamp,
    ) {}

    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'confidence' => $this->confidence,
            'won' => $this->won,
            'pnl_percent' => $this->pnlPercent,
            'timestamp' => $this->timestamp,
        ];
    }
}
