<?php

namespace App\Bot\Backtest;

/**
 * An open simulated position. Pure value object — engine mutates via with*().
 */
class SimPosition
{
    public function __construct(
        public readonly float $entryPrice,
        public readonly float $quantity,
        public readonly float $stopLoss,
        public readonly float $takeProfit,
        public readonly int $entryTs,
        public readonly int $entryIndex,
        public readonly float $confidence,
    ) {}

    public function withStopLoss(float $sl): self
    {
        return new self($this->entryPrice, $this->quantity, $sl, $this->takeProfit, $this->entryTs, $this->entryIndex, $this->confidence);
    }

    /** Net PnL% after round-trip fees, relative to invested notional. */
    public function pnlPercent(float $exitPrice, float $feePct): float
    {
        if ($this->entryPrice <= 0) {
            return 0.0;
        }

        $gross = (($exitPrice - $this->entryPrice) / $this->entryPrice) * 100;

        return $gross - (2 * $feePct);
    }
}
