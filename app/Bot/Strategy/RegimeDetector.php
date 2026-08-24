<?php

namespace App\Bot\Strategy;

use App\Bot\Features\FeatureSet;
use Illuminate\Support\Facades\Config;

/**
 * ADX-based market regime detection — port of strategy/regime.py
 * Pure logic, no I/O.
 */
class RegimeDetector
{
    public function detect(FeatureSet $features, ?float $bbSqueezeThreshold = null): MarketRegime
    {
        $threshold = $bbSqueezeThreshold ?? (float) Config::get('bot.validation.bb_squeeze_threshold', 1.5);

        if ($features->length() < 20) {
            return new MarketRegime('UNKNOWN', 0, 'NONE', 'NORMAL', false, false, 0, 'insufficient_data');
        }

        $adx = $features->last('adx') ?? 0;
        if (! is_finite($adx)) {
            $adx = 0;
        }

        $plusDi = $features->last('plus_di') ?? 0;
        $minusDi = $features->last('minus_di') ?? 0;
        if (! is_finite($plusDi)) {
            $plusDi = 0;
        }
        if (! is_finite($minusDi)) {
            $minusDi = 0;
        }

        // Trend strength
        if ($adx > 30) {
            $trendStrength = 'STRONG';
        } elseif ($adx > 25) {
            $trendStrength = 'MODERATE';
        } elseif ($adx > 20) {
            $trendStrength = 'WEAK';
        } else {
            $trendStrength = 'NONE';
        }

        // Regime classification
        if ($adx >= 25) {
            $regime = 'TRENDING';
        } elseif ($adx >= 20) {
            $regime = 'TRANSITIONAL';
        } else {
            $regime = 'RANGING';
        }

        // Volatility regime
        $atrPct = $features->last('atr_pct') ?? 0;
        if (! is_finite($atrPct)) {
            $atrPct = 0;
        }

        if ($atrPct < 0.05) {
            $volatility = 'LOW';
        } elseif ($atrPct < 0.5) {
            $volatility = 'NORMAL';
        } elseif ($atrPct < 1.5) {
            $volatility = 'HIGH';
        } else {
            $volatility = 'EXTREME';
        }

        // BB Squeeze
        $bbWidth = $features->last('bb_width') ?? 0;
        if (! is_finite($bbWidth)) {
            $bbWidth = 0;
        }
        $bbSqueeze = $bbWidth < $threshold;

        // Tradeability + confidence adjustment
        $tradeable = true;
        $confAdj = 0.0;
        $reasons = [];

        if ($regime === 'RANGING') {
            $tradeable = false;
            $confAdj -= 25;
            $reasons[] = 'ranging_market';
        } elseif ($regime === 'TRANSITIONAL') {
            $confAdj -= 10;
            $reasons[] = 'transitional';
        } elseif ($regime === 'TRENDING' && $trendStrength === 'STRONG') {
            $confAdj += 10;
            $reasons[] = 'strong_trend';
        }

        if ($volatility === 'EXTREME' && $regime !== 'TRENDING') {
            $tradeable = false;
            $confAdj -= 20;
            $reasons[] = 'extreme_volatility_in_ranging';
        }

        if ($volatility === 'LOW') {
            $confAdj -= 15;
            $reasons[] = 'low_volatility';
        }

        if ($bbSqueeze && $regime === 'TRENDING') {
            $confAdj += 5;
            $reasons[] = 'squeeze_in_trend';
        } elseif ($bbSqueeze && $regime === 'RANGING') {
            $confAdj -= 5;
            $reasons[] = 'squeeze_in_range';
        }

        if ($plusDi > $minusDi && $plusDi > 20) {
            $confAdj += 5;
            $reasons[] = 'bullish_di_alignment';
        } elseif ($minusDi > $plusDi && $minusDi > 20) {
            $confAdj -= 10;
            $reasons[] = 'bearish_di_alignment';
        }

        $confAdj = max(-30.0, min(20.0, $confAdj));
        $reason = $reasons !== [] ? implode(', ', $reasons) : 'neutral';

        return new MarketRegime(
            regime: $regime,
            adx: $adx,
            trendStrength: $trendStrength,
            volatility: $volatility,
            bbSqueeze: $bbSqueeze,
            tradeable: $tradeable,
            confidenceAdjustment: $confAdj,
            reason: $reason,
        );
    }
}
