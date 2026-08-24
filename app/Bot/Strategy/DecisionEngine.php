<?php

namespace App\Bot\Strategy;

use App\Bot\Features\FeatureSet;
use App\Bot\Features\Indicators;
use App\Bot\MarketData\CandleFetcher;
use App\Bot\MarketData\CandleSeries;
use App\Bot\MarketData\DataQualityReport;
use App\Bot\Scoring\ReliabilityTracker;
use App\Bot\Scoring\ScoreCalibration;
use App\Bot\Scoring\ScoringEngine;
use App\Bot\Scoring\ScoringResult;
use App\Bot\Validation\Validator;
use Illuminate\Support\Facades\Config;

/**
 * Main Decision Pipeline — port of strategy/decision.py
 * SPOT-ONLY: BUY entries, HOLD, EXIT (no short)
 */
class DecisionEngine
{
    public function __construct(
        protected ScoringEngine $scoringEngine,
        protected ScoreCalibration $calibration,
        protected CandleFetcher $candleFetcher,
        protected Validator $validator,
        protected RegimeDetector $regimeDetector,
    ) {}

    public function evaluateExit(
        FeatureSet $features,
        CandleSeries $series,
    ): ?Decision {
        // Kill-switch for experimentation/backtests — pure SL/TP management
        if (! Config::get('bot.trade_manager.indicator_exit_enabled', true)) {
            return null;
        }

        $exitSignal = $this->scoringEngine->computeExitSignal($features);

        if ($exitSignal->shouldExit) {
            return new Decision(
                action: Decision::EXIT,
                reason: "indicator_exit: {$exitSignal->reason} (strength=".number_format($exitSignal->strength, 0).')',
                exitSignal: $exitSignal,
            );
        }

        return null;
    }

    /**
     * Main pipeline entry point (SPOT-ONLY).
     *
     * @param  float  $usdtBalance  Current USDT balance
     * @param  bool  $inPosition  Whether we have an open position
     * @param  object  $circuitBreaker  Must implement isTradingAllowed(): array{bool, string}
     * @param  FeatureSet|null  $htfFeatures  Higher-timeframe features (optional)
     */
    public function makeDecision(
        CandleSeries $series,
        DataQualityReport $qualityReport,
        float $usdtBalance,
        bool $inPosition,
        object $circuitBreaker,
        ?FeatureSet $htfFeatures = null,
        ?ReliabilityTracker $reliabilityTracker = null,
    ): Decision {
        // --- STAGE: INPUT VALIDATION ---
        $inputValidation = $this->validator->validatePipelineInput($series, $qualityReport);
        if (! $inputValidation->passed) {
            return new Decision(
                action: Decision::SKIP_INSUFFICIENT_DATA,
                reason: $inputValidation->summary(),
            );
        }

        // --- STAGE: CIRCUIT BREAKER CHECK ---
        [$allowed, $cbReason] = $circuitBreaker->isTradingAllowed();
        if (! $allowed && ! $inPosition) {
            return new Decision(
                action: Decision::SKIP_RISK_LIMIT,
                reason: "circuit_breaker: {$cbReason}",
            );
        }

        // --- STAGE: FEATURE EXTRACTION ---
        $features = Indicators::computeAll($series, Config::get('bot.indicators'));

        // --- STAGE: MARKET REGIME ---
        $regime = $this->regimeDetector->detect($features);
        if (! $regime->tradeable && ! $inPosition) {
            return new Decision(
                action: Decision::HOLD,
                reason: "regime_not_tradeable: {$regime->regime} ({$regime->reason})",
            );
        }

        // --- If in position: check indicator-based exit first ---
        if ($inPosition) {
            $exitDecision = $this->evaluateExit($features, $series);
            if ($exitDecision !== null) {
                return $exitDecision;
            }

            // No exit signal -> HOLD
            return new Decision(
                action: Decision::HOLD,
                reason: 'in_position_no_exit_signal',
            );
        }

        // --- STAGE: HIGHER-TIMEFRAME TREND ---
        $htfDirection = null;
        if ($htfFeatures !== null) {
            $htfDirection = $this->htfDirection($htfFeatures);
        }

        // --- STAGE: SCORING (BUY only) ---
        $scoringResult = $this->scoringEngine->computeScore(
            $features, $series, $htfFeatures, $reliabilityTracker
        );

        // --- STAGE: PHASE 2 GATES (HTF hard gate + regime-split) ---
        $scoringResult = $this->applyPhase2Gates($scoringResult, $htfDirection, $features);

        // --- STAGE: DECISION VALIDATION ---
        $decisionValidation = $this->validator->validateScoringResult($scoringResult);
        if (! $decisionValidation->passed) {
            $action = ($scoringResult->direction !== 'NEUTRAL')
                ? Decision::WAIT_LOW_CONFIDENCE
                : Decision::HOLD;

            return new Decision(
                action: $action,
                confidence: $scoringResult->finalConfidence,
                band: $scoringResult->band,
                reason: $decisionValidation->summary(),
                scoringResult: $scoringResult,
            );
        }

        if ($scoringResult->direction !== 'BUY') {
            return new Decision(
                action: Decision::HOLD,
                confidence: $scoringResult->finalConfidence,
                band: $scoringResult->band,
                reason: 'no_buy_signal',
                scoringResult: $scoringResult,
            );
        }

        // --- STAGE: RISK ASSESSMENT (Phase 7 will implement) ---
        $currentPrice = $series->last()->close ?? 0;
        $atr = $features->last('atr');

        // Delegate to RiskAssessor (Phase 7) — for now stub
        // $riskAssessment = $this->riskAssessor->assess(...);
        // For now skip and return BUY with placeholder
        // TODO: inject RiskAssessor in constructor

        // --- FINAL DECISION: BUY ---
        return new Decision(
            action: Decision::BUY,
            confidence: $scoringResult->finalConfidence,
            band: $scoringResult->band,
            reason: "passed_all_gates: {$scoringResult->band} confidence",
            scoringResult: $scoringResult,
            riskAssessment: null, // Phase 7
        );
    }

    protected function applyPhase2Gates(
        ScoringResult $sr,
        ?string $htfDirection,
        FeatureSet $features,
    ): ScoringResult {
        if ($sr === null || $sr->direction !== 'BUY') {
            return $sr;
        }

        $val = Config::get('bot.validation');
        $blockers = [];

        // 1) HTF hard gate
        if (($val['htf_hard_gate'] ?? true) && $htfDirection === 'BEARISH') {
            $sr->finalConfidence = min($sr->finalConfidence, 55);
            $blockers[] = 'htf_bearish_counter_trend';
        }

        // 2) Regime-split gate (entry LOCATION requirement)
        if (($val['regime_split_gate'] ?? true)) {
            $adx = $features->last('adx');
            $struct = 50.0;

            foreach ($sr->factors as $f) {
                if ($f->name === 'structure') {
                    $struct = $f->score;
                    break;
                }
            }

            if (is_finite($adx)) {
                if ($adx < 20) {
                    // Ranging: need range-edge structure
                    if ($struct < (float) ($val['structure_min_ranging'] ?? 65)) {
                        $sr->finalConfidence = min($sr->finalConfidence, 55);
                        $blockers[] = "ranging_needs_range_edge(struct={$struct})";
                    }
                } else {
                    // Trending: weak structure = chasing
                    if ($struct < (float) ($val['structure_min_trending'] ?? 35)) {
                        $sr->finalConfidence = min($sr->finalConfidence, 58);
                        $blockers[] = "weak_structure_chasing(struct={$struct})";
                    }
                }
            }
        }

        if ($blockers !== []) {
            $sr->criticalBlockers = array_unique(array_merge($sr->criticalBlockers, $blockers));
            $bands = Config::get('bot.confidence_bands');
            $sr->band = ScoringEngine::confidenceToBand($sr->finalConfidence, $bands);
        }

        return $sr;
    }

    protected function htfDirection(?FeatureSet $htfFeatures): ?string
    {
        if ($htfFeatures === null) {
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
}
