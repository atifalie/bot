<?php

namespace App\Bot\Strategy;

/**
 * Trailing stop-loss — port of strategy/trailing_stop.py
 * Pure math, no I/O.
 */
class TrailingStop
{
    public static function update(
        float $entryPrice,
        float $currentPrice,
        float $originalStopLoss,
        float $atr,
        float $trailMultiplier = 1.5,
        float $minProfitLockPct = 2.5,
    ): float {
        if ($currentPrice <= $entryPrice) {
            return $originalStopLoss;
        }

        $profitPct = (($currentPrice - $entryPrice) / $entryPrice) * 100;

        // Phase 1: Below min profit lock — keep original SL
        if ($profitPct < $minProfitLockPct) {
            return $originalStopLoss;
        }

        // Phase 2: Profit lock — move SL to breakeven once min profit reached
        if ($profitPct >= $minProfitLockPct && $originalStopLoss < $entryPrice) {
            $originalStopLoss = $entryPrice;
        }

        // Phase 3: Trailing — SL follows price with ATR distance
        if ($atr > 0) {
            $trailingSl = $currentPrice - ($atr * $trailMultiplier);
            $newSl = max($trailingSl, $originalStopLoss);

            return $newSl;
        }

        return $originalStopLoss;
    }
}
