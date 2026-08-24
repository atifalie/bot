<?php

namespace App\Bot\Scoring;

use App\Bot\Features\FeatureSet;
use App\Bot\Features\StructureAnalyzer;
use App\Bot\MarketData\CandleSeries;
use Illuminate\Support\Facades\Config;

/**
 * Core scoring engine — 12 independent factor scores (0-100) combined via
 * config weights, critical blocker override (cap at 55), confidence banding.
 * Pure functions, no I/O. Structure factor delegates to StructureAnalyzer.
 */
class ScoringEngine
{
    protected const BAND_ORDER = ['exceptional', 'strong', 'acceptable', 'weak', 'reject'];

    public function __construct(
        protected ScoreCalibration $calibration,
    ) {}

    public function computeScore(
        FeatureSet $features,
        CandleSeries $series,
        ?FeatureSet $htfFeatures = null,
        ?ReliabilityTracker $reliabilityTracker = null,
    ): ?ScoringResult {
        if ($features->length() < 2) {
            return null;
        }

        $weights = Config::get('bot.weights');
        $bands = Config::get('bot.confidence_bands');
        $calib = $this->calibration;

        $trend = $this->scoreTrend($features, $calib);
        $momentum = $this->scoreMomentum($features);
        $volume = $this->scoreVolume($features);
        $volatility = $this->scoreVolatility($features, $calib);
        $macd = $this->scoreMacd($features, $calib);
        $bollinger = $this->scoreBollinger($features, $calib);
        $vwap = $this->scoreVwap($features);
        $stochRsi = $this->scoreStochRsi($features);

        $direction = $this->determineDirection($features);

        $reliability = $this->scoreReliability($direction, $reliabilityTracker);
        $htfTrend = $this->scoreHtfTrend($direction, $htfFeatures);
        $regime = $this->scoreRegime($features, $direction);

        $structure = $this->scoreStructure($series, $features->last('atr'));

        $factorMap = [
            'trend' => [$trend, $weights['trend']],
            'momentum' => [$momentum, $weights['momentum']],
            'volume' => [$volume, $weights['volume']],
            'volatility' => [$volatility, $weights['volatility']],
            'macd' => [$macd, $weights['macd']],
            'bollinger' => [$bollinger, $weights['bollinger']],
            'vwap' => [$vwap, $weights['vwap']],
            'stoch_rsi' => [$stochRsi, $weights['stoch_rsi']],
            'reliability' => [$reliability, $weights['reliability']],
            'htf_trend' => [$htfTrend, $weights['htf_trend']],
            'regime' => [$regime, $weights['regime']],
            'structure' => [$structure, $weights['structure']],
        ];

        $weightedSum = 0.0;
        $factors = [];
        $criticalBlockers = [];

        foreach ($factorMap as $name => [$factor, $weight]) {
            $factor->weight = $weight;
            $weightedSum += $factor->score * $weight;
            $factors[] = $factor;
            if ($factor->isCriticalBlocker) {
                $criticalBlockers[] = $name;
            }
        }

        $finalConfidence = $weightedSum;

        if ($criticalBlockers !== []) {
            $finalConfidence = min($finalConfidence, 55.0);
        }

        $finalConfidence = max(0.0, min(100.0, $finalConfidence));

        if ($direction === 'NEUTRAL') {
            $finalConfidence = min($finalConfidence, $bands['weak'] - 1);
        }

        $band = self::confidenceToBand($finalConfidence, $bands);

        return new ScoringResult(
            rawWeightedScore: $weightedSum,
            finalConfidence: $finalConfidence,
            band: $band,
            factors: $factors,
            criticalBlockers: $criticalBlockers,
            direction: $direction,
        );
    }

    public function computeExitSignal(
        FeatureSet $features,
        ?ReliabilityTracker $reliabilityTracker = null,
    ): ExitSignal {
        if ($features->length() < 2) {
            return new ExitSignal(false, 'insufficient_data', 0);
        }

        $exitSignals = [];

        // 1. Bearish MA crossover — confirmed on current candle
        if ($this->isBearishCrossover($features)) {
            // Check if fresh crossover (prev was bullish/aligned) or sustained
            $isFresh = $this->isFreshBearishCrossover($features);
            $strength = $isFresh ? 85 : 75;
            $exitSignals[] = ['bearish_crossover', $strength];
        }

        // 2. RSI overbought
        $rsi = $features->last('rsi');
        if (is_finite($rsi) && $rsi > 80) {
            $exitSignals[] = ['rsi_overbought', min(90, 50 + ($rsi - 80) * 4)];
        }

        // 3. Bollinger upper band touch
        $bbPctB = $features->last('bb_pct_b');
        if (is_finite($bbPctB) && $bbPctB > 0.9) {
            $exitSignals[] = ['bb_upper_touch', min(85, 50 + ($bbPctB - 0.9) * 350)];
        }

        // 4. VWAP overextended
        $vwapDist = $features->last('vwap_distance_pct');
        if (is_finite($vwapDist) && $vwapDist > 20.0) {
            $exitSignals[] = ['vwap_overextended', min(80, 50 + ($vwapDist - 20.0) * 2)];
        }

        // 5. Stoch RSI bearish crossover in overbought
        $k = $features->last('stoch_rsi_k');
        $d = $features->last('stoch_rsi_d');
        if (is_finite($k) && is_finite($d) && $k > 80 && $k < $d) {
            $exitSignals[] = ['stoch_rsi_bearish_cross', 75];
        }

        if ($exitSignals === []) {
            return new ExitSignal(false, 'hold', 0);
        }

        usort($exitSignals, fn ($a, $b) => $b[1] <=> $a[1]);
        $bestReason = $exitSignals[0][0];
        $bestStrength = $exitSignals[0][1];

        if (count($exitSignals) >= 2) {
            $bestStrength = min(100, $bestStrength + 10);
        }

        return new ExitSignal(
            shouldExit: $bestStrength >= 70,
            reason: $bestReason,
            strength: $bestStrength,
            allSignals: array_column($exitSignals, 0),
        );
    }

    protected function determineDirection(FeatureSet $features): string
    {
        $fastMa = $features->last('fast_ma');
        $slowMa = $features->last('slow_ma');
        $prevFast = $features->column('fast_ma')[$features->length() - 2] ?? NAN;
        $prevSlow = $features->column('slow_ma')[$features->length() - 2] ?? NAN;

        $crossedUp = is_finite($prevFast) && is_finite($prevSlow)
            && $prevFast <= $prevSlow && is_finite($fastMa) && is_finite($slowMa) && $fastMa > $slowMa;

        $alreadyBullish = is_finite($fastMa) && is_finite($slowMa) && $fastMa > $slowMa;

        return ($crossedUp || $alreadyBullish) ? 'BUY' : 'NEUTRAL';
    }

    protected function isBearishCrossover(FeatureSet $features): bool
    {
        $fastMa = $features->last('fast_ma');
        $slowMa = $features->last('slow_ma');

        return is_finite($fastMa) && is_finite($slowMa) && $fastMa < $slowMa;
    }

    protected function isFreshBearishCrossover(FeatureSet $features): bool
    {
        if ($features->length() < 3) {
            return true; // Assume fresh if no history
        }

        $prevFast = $features->column('fast_ma')[$features->length() - 2];
        $prevSlow = $features->column('slow_ma')[$features->length() - 2];
        $prev2Fast = $features->column('fast_ma')[$features->length() - 3];
        $prev2Slow = $features->column('slow_ma')[$features->length() - 3];

        if (! is_finite($prevFast) || ! is_finite($prevSlow) || ! is_finite($prev2Fast) || ! is_finite($prev2Slow)) {
            return true;
        }

        // Fresh crossover on prev candle: prev was bullish, current is bearish
        if ($prev2Fast >= $prev2Slow && $prevFast < $prevSlow) {
            return true;
        }

        // Sustained: prev was already bearish, current also bearish
        if ($prevFast < $prevSlow) {
            return false;
        }

        return true;
    }

    protected function htfDirection(?FeatureSet $htfFeatures): ?string
    {
        if ($htfFeatures === null || ! $htfFeatures->has('fast_ma') || ! $htfFeatures->has('slow_ma')) {
            return null;
        }

        $fast = $htfFeatures->last('fast_ma');
        $slow = $htfFeatures->last('slow_ma');

        if (! is_finite($fast) || ! is_finite($slow)) {
            return null;
        }

        if ($fast > $slow) {
            return 'BULLISH';
        }
        if ($fast < $slow) {
            return 'BEARISH';
        }

        return 'NEUTRAL';
    }

    // ===== FACTOR SCORING METHODS =====

    protected function scoreTrend(FeatureSet $features, ScoreCalibration $calib): FactorScore
    {
        $fastMa = $features->last('fast_ma');
        $slowMa = $features->last('slow_ma');
        $prevFast = $features->column('fast_ma')[$features->length() - 2] ?? NAN;
        $prevSlow = $features->column('slow_ma')[$features->length() - 2] ?? NAN;
        $sep = $features->last('ma_separation_pct');

        if (! is_finite($fastMa) || ! is_finite($slowMa) || ! is_finite($prevFast)) {
            return new FactorScore('trend', 0, 0, false, 'insufficient_data');
        }

        $crossedUp = $prevFast <= $prevSlow && $fastMa > $slowMa;
        $separation = abs($sep);
        $strengthFromSep = min(100, $separation * $calib->trendSeparationScale);

        if ($crossedUp) {
            $score = 60 + $strengthFromSep * 0.4;

            return new FactorScore('trend', min(100, $score), 0, false,
                sprintf('bullish_crossover sep=%.2f%%', $separation));
        }

        if ($fastMa > $slowMa) {
            $score = 55 + min(30, $strengthFromSep * 0.3);

            return new FactorScore('trend', min(100, $score), 0, false,
                sprintf('bullish_aligned sep=%.2f%%', $separation));
        }

        return new FactorScore('trend', max(0, 15 - $strengthFromSep * 0.05), 0, false,
            sprintf('bearish_aligned sep=%.2f%%', $separation));
    }

    protected function scoreMomentum(FeatureSet $features): FactorScore
    {
        $rsi = $features->last('rsi');

        if (! is_finite($rsi)) {
            return new FactorScore('momentum', 50, 0, false, 'rsi_unavailable');
        }

        // TREND FOLLOWING: higher RSI = stronger momentum = good for BUY
        if ($rsi >= 70) {
            $score = 80 + min(5, ($rsi - 70) * 0.5);
        } elseif ($rsi >= 55) {
            $score = 55 + ($rsi - 55) * 1.67;
        } elseif ($rsi >= 40) {
            $score = max(30, 55 - (55 - $rsi) * 1.0);
        } elseif ($rsi >= 25) {
            $score = max(10, 30 - (40 - $rsi) * 1.33);
        } else {
            $score = max(0, 10 - (25 - $rsi) * 0.5);
        }

        $isBlocker = $rsi <= 25;

        return new FactorScore('momentum', min(100, max(0, $score)), 0, $isBlocker,
            sprintf('rsi=%.1f', $rsi));
    }

    protected function scoreVolume(FeatureSet $features): FactorScore
    {
        $relVol = $features->last('relative_volume');

        if (! is_finite($relVol)) {
            return new FactorScore('volume', 50, 0, false, 'volume_unavailable');
        }

        $score = min(100, max(0, ($relVol - 0.3) * 60));

        return new FactorScore('volume', $score, 0, false,
            sprintf('relative_volume=%.2fx', $relVol));
    }

    protected function scoreVolatility(FeatureSet $features, ScoreCalibration $calib): FactorScore
    {
        $atrPct = $features->last('atr_pct');

        if (! is_finite($atrPct)) {
            return new FactorScore('volatility', 50, 0, false, 'atr_unavailable');
        }

        $dead = $calib->atrPctDeadThreshold;
        $extreme = $calib->atrPctExtremeThreshold;
        $ideal = $calib->atrPctIdealCenter;

        if ($atrPct < $dead) {
            $score = 40;
            $explanation = sprintf('atr%%=%.3f (too flat/dead market)', $atrPct);
        } elseif ($atrPct > $extreme) {
            $score = 20;
            $explanation = sprintf('atr%%=%.3f (extreme volatility, unpredictable)', $atrPct);
        } else {
            $dist = abs($atrPct - $ideal);
            $score = max(30, 100 - $dist * 40);
            $explanation = sprintf('atr%%=%.3f (healthy range)', $atrPct);
        }

        $isBlocker = $atrPct > $calib->atrPctCriticalBlocker;

        return new FactorScore('volatility', $score, 0, $isBlocker, $explanation);
    }

    protected function scoreMacd(FeatureSet $features, ScoreCalibration $calib): FactorScore
    {
        $hist = $features->last('macd_histogram');

        if (! is_finite($hist)) {
            return new FactorScore('macd', 50, 0, false, 'macd_unavailable');
        }

        $magnitude = abs($hist) * $calib->macdMagnitudeScale;

        if ($hist > 0) {
            $score = min(100, 55 + $magnitude);
        } else {
            $score = max(15, 55 - $magnitude * 0.8);
        }

        return new FactorScore('macd', $score, 0, false,
            sprintf('macd_hist=%.5f', $hist));
    }

    protected function scoreBollinger(FeatureSet $features, ScoreCalibration $calib): FactorScore
    {
        $bbPctB = $features->last('bb_pct_b');
        $bbWidth = $features->last('bb_width');

        if (! is_finite($bbPctB)) {
            return new FactorScore('bollinger', 50, 0, false, 'bb_unavailable');
        }

        if ($bbPctB >= 0.95) {
            $score = 55;
        } elseif ($bbPctB >= 0.9) {
            $score = 65;
        } elseif ($bbPctB >= 0.7) {
            $score = 65 + ($bbPctB - 0.7) * 100;
        } elseif ($bbPctB >= 0.5) {
            $score = 45 + ($bbPctB - 0.5) * 100;
        } elseif ($bbPctB >= 0.3) {
            $score = max(20, 45 - (0.5 - $bbPctB) * 125);
        } else {
            $score = max(0, 20 - (0.3 - $bbPctB) * 67);
        }

        if (is_finite($bbWidth) && $bbWidth < 1.5) {
            $score = min(100, $score + 10);
        }

        $isBlocker = $bbPctB < 0.15;
        $explanation = sprintf('bb_pct_b=%.2f', $bbPctB);
        if (is_finite($bbWidth)) {
            $explanation .= sprintf(' bb_width=%.2f', $bbWidth);
        }

        return new FactorScore('bollinger', min(100, $score), 0, $isBlocker, $explanation);
    }

    protected function scoreVwap(FeatureSet $features): FactorScore
    {
        $dist = $features->last('vwap_distance_pct');

        if (! is_finite($dist)) {
            return new FactorScore('vwap', 50, 0, false, 'vwap_unavailable');
        }

        // TREND-FOLLOWING alignment: riding/holding above VWAP = healthy uptrend.
        // Deep BELOW VWAP = falling knife — never "cheap" for a long-only system.
        if ($dist <= -3.5) {
            // knife: collapsing far below value area — block entries
            return new FactorScore('vwap', 8, 0, true,
                sprintf('vwap_dist=%.2f%% (falling_knife_below_vwap)', $dist));
        }

        if ($dist <= -1.5) {
            $score = max(12, 32 - (-1.5 - $dist) * 14); // 32→12 knife approach
        } elseif ($dist <= -0.3) {
            $score = 48 + ($dist + 1.5) * 11;           // shallow pullback 48→61
        } elseif ($dist <= 1.5) {
            $score = 72 + abs($dist + 0.3) * 4;          // riding VWAP 72-79 sweet spot
        } elseif ($dist <= 5.0) {
            $score = max(58, 78 - ($dist - 1.5) * 4);    // extended but ok
        } elseif ($dist <= 15.0) {
            $score = max(38, 58 - ($dist - 5.0) * 2);    // overextension decaying
        } else {
            $score = max(10, 38 - ($dist - 15.0) * 1.6); // blow-off zone
        }

        $isBlocker = $dist > 35.0;

        return new FactorScore('vwap', min(100, $score), 0, $isBlocker,
            sprintf('vwap_dist=%.2f%%', $dist));
    }

    protected function scoreStochRsi(FeatureSet $features): FactorScore
    {
        $k = $features->last('stoch_rsi_k');
        $d = $features->last('stoch_rsi_d');
        $fastMa = $features->last('fast_ma');
        $slowMa = $features->last('slow_ma');

        if (! is_finite($k) || ! is_finite($d)) {
            return new FactorScore('stoch_rsi', 50, 0, false, 'stoch_rsi_unavailable');
        }

        $inUptrend = is_finite($fastMa) && is_finite($slowMa) && $fastMa > $slowMa;

        if ($inUptrend) {
            if ($k < 20 && $k > $d) {
                $score = 80;
            } elseif ($k < 30) {
                $score = 65 + (30 - $k);
            } elseif ($k < 50) {
                $score = 50 + (50 - $k) * 0.5;
            } elseif ($k < 70) {
                $score = 55;
            } elseif ($k < 85) {
                $score = 60 + ($k - 70) * 0.5;
            } else {
                $score = 50;
            }
        } else {
            if ($k < 20) {
                $score = max(15, 40 - (20 - $k) * 1.5);
            } elseif ($k < 50) {
                $score = 35 + ($k - 20) * 0.5;
            } elseif ($k < 80) {
                $score = max(25, 50 - ($k - 50) * 0.5);
            } else {
                $score = max(10, 25 - ($k - 80) * 0.75);
            }
        }

        $isBlocker = (! $inUptrend && $k < 15);

        return new FactorScore('stoch_rsi', min(100, max(0, $score)), 0, $isBlocker,
            sprintf('stoch_k=%.1f stoch_d=%.1f', $k, $d));
    }

    protected function scoreHtfTrend(string $direction, ?FeatureSet $htfFeatures): FactorScore
    {
        $htfDir = $this->htfDirection($htfFeatures);

        if ($htfDir === null) {
            return new FactorScore('htf_trend', 50, 0, false, 'htf_data_unavailable');
        }

        if ($direction === 'BUY') {
            if ($htfDir === 'BULLISH') {
                return new FactorScore('htf_trend', 90, 0, false, 'aligned_with_bullish_htf');
            }
            if ($htfDir === 'BEARISH') {
                return new FactorScore('htf_trend', 10, 0, true, 'counter_trend_vs_bearish_htf');
            }
        }

        return new FactorScore('htf_trend', 50, 0, false, 'htf_neutral');
    }

    protected function scoreReliability(string $direction, ?ReliabilityTracker $tracker): FactorScore
    {
        if ($tracker === null) {
            return new FactorScore('reliability', 50, 0, false, 'no_tracker_available');
        }

        [$winRate, $sampleSize] = $tracker->getWinRate($direction);

        if ($sampleSize < 10) {
            return new FactorScore('reliability', 50, 0, false,
                sprintf('insufficient_samples(%d)', $sampleSize));
        }

        $score = $winRate * 100;

        return new FactorScore('reliability', $score, 0, false,
            sprintf('win_rate=%.2f n=%d', $winRate, $sampleSize));
    }

    protected function scoreRegime(FeatureSet $features, string $direction = 'NEUTRAL'): FactorScore
    {
        $adx = $features->last('adx');
        $plusDi = $features->last('plus_di');
        $minusDi = $features->last('minus_di');

        if (! is_finite($adx)) {
            return new FactorScore('regime', 50, 0, false, 'adx_unavailable');
        }

        $plusDi = is_finite($plusDi) ? $plusDi : 0;
        $minusDi = is_finite($minusDi) ? $minusDi : 0;

        if ($adx >= 30) {
            $score = 90;
            $explanation = sprintf('adx=%.1f (strong_trend)', $adx);
        } elseif ($adx >= 25) {
            $score = 70 + ($adx - 25) * 4;
            $explanation = sprintf('adx=%.1f (moderate_trend)', $adx);
        } elseif ($adx >= 20) {
            $score = 40 + ($adx - 20) * 6;
            $explanation = sprintf('adx=%.1f (weak_trend)', $adx);
        } else {
            $score = max(10, 40 - (20 - $adx) * 3);
            $explanation = sprintf('adx=%.1f (ranging)', $adx);
        }

        if ($plusDi > $minusDi && $plusDi > 20) {
            $score = min(100, $score + 10);
            $explanation .= ' +bullish_di';
        } elseif ($minusDi > $plusDi && $minusDi > 20) {
            $score = max(0, $score - 15);
            $explanation .= ' -bearish_di';
        }

        // DIRECTIONAL GATE: ADX measures strength, not direction. A strong
        // BEARISH trend must not fund long entries via LTF bear-rally MAs.
        $isBlocker = $adx < 18;
        if ($direction === 'BUY' && $minusDi > $plusDi && $minusDi > 25) {
            $isBlocker = true;
            $score = min($score, 20);
            $explanation .= ' [BLOCKER] buying_into_bearish_trend';
        }

        return new FactorScore('regime', min(100, max(0, $score)), 0, $isBlocker, $explanation);
    }

    protected function scoreStructure(CandleSeries $series, ?float $atrValue): FactorScore
    {
        if ($atrValue === null || ! is_finite($atrValue)) {
            $atrValue = 0.0;
        }

        $analyzer = new StructureAnalyzer($series, $atrValue);
        $result = $analyzer->structureFactor();

        return new FactorScore('structure', $result['score'], 0, false,
            sprintf('structure=%s', $result['note']));
    }

    public static function confidenceToBand(float $score, array $bands): string
    {
        if ($score >= $bands['exceptional']) {
            return 'EXCEPTIONAL';
        }
        if ($score >= $bands['strong']) {
            return 'STRONG';
        }
        if ($score >= $bands['acceptable']) {
            return 'ACCEPTABLE';
        }
        if ($score >= $bands['weak']) {
            return 'WEAK';
        }

        return 'REJECT';
    }
}
