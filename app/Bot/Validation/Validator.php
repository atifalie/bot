<?php

namespace App\Bot\Validation;

use App\Bot\MarketData\CandleSeries;
use App\Bot\MarketData\DataQualityReport;
use App\Bot\Scoring\ScoringResult;
use Illuminate\Support\Facades\Config;

/**
 * Validation gates — run BEFORE any trade decision. If any gate fails,
 * the decision is SKIP regardless of scoring.
 */
class Validator
{
    public function validatePipelineInput(
        ?CandleSeries $series,
        DataQualityReport $qualityReport,
    ): ValidationResult {
        $config = Config::get('bot.validation');
        $failed = [];

        if ($series === null || $series->isEmpty()) {
            $failed[] = 'empty_series';

            return new ValidationResult(false, $failed);
        }

        if ($series->count() < ($config['min_candles_required'] ?? 150)) {
            $failed[] = sprintf('insufficient_candles(%d<%d)',
                $series->count(), $config['min_candles_required'] ?? 150);
        }

        if (! $qualityReport->isValid) {
            $failed[] = 'data_quality_report_invalid';
        }

        $maxNull = $config['max_allowed_null_percent'] ?? 2.0;
        if ($qualityReport->nullPercent > $maxNull) {
            $failed[] = sprintf('null_percent_too_high(%.2f)', $qualityReport->nullPercent);
        }

        if ($qualityReport->gapCount > 0) {
            $failed[] = sprintf('data_gaps_detected(%d)', $qualityReport->gapCount);
        }

        return new ValidationResult(count($failed) === 0, $failed);
    }

    public function validateScoringResult(?ScoringResult $result): ValidationResult
    {
        $config = Config::get('bot.validation');
        $failed = [];

        if ($result === null) {
            $failed[] = 'scoring_result_none';

            return new ValidationResult(false, $failed);
        }

        if ($result->direction === 'NEUTRAL') {
            $failed[] = 'no_directional_signal';
        }

        // BUGFIX: reject floor is min(55, min_confidence_to_act) — ensures
        // "REJECT" band literally never trades regardless of config.
        $minConf = $config['min_confidence_to_act'] ?? 70;
        $rejectFloor = min(55, $minConf);

        if ($result->finalConfidence < $rejectFloor) {
            $failed[] = sprintf('band_is_reject(confidence=%.1f)', $result->finalConfidence);
        }

        if ($result->finalConfidence < $minConf) {
            $failed[] = sprintf('confidence_below_threshold(%.1f<%d)',
                $result->finalConfidence, $minConf);
        }

        return new ValidationResult(count($failed) === 0, $failed);
    }
}
