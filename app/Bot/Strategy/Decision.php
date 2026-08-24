<?php

namespace App\Bot\Strategy;

use App\Bot\Risk\RiskAssessment;
use App\Bot\Scoring\ExitSignal;
use App\Bot\Scoring\ScoringResult;

/**
 * Decision DTO — port of strategy/decision.py Decision dataclass
 */
class Decision
{
    public const BUY = 'BUY';

    public const HOLD = 'HOLD';

    public const EXIT = 'EXIT';

    public const WAIT_LOW_CONFIDENCE = 'WAIT_LOW_CONFIDENCE';

    public const SKIP_INSUFFICIENT_DATA = 'SKIP_INSUFFICIENT_DATA';

    public const SKIP_HIGH_RISK = 'SKIP_HIGH_RISK';

    public const SKIP_RISK_LIMIT = 'SKIP_RISK_LIMIT';

    public function __construct(
        public string $action,
        public ?float $confidence = null,
        public ?string $band = null,
        public string $reason = '',
        public ?ScoringResult $scoringResult = null,
        public ?RiskAssessment $riskAssessment = null,
        public ?ExitSignal $exitSignal = null,
        public array $explainLog = [],
    ) {}

    public function fullExplanation(): string
    {
        $lines = ["DECISION: {$this->action} | reason: {$this->reason}"];

        if ($this->scoringResult !== null) {
            $lines[] = $this->scoringResult->explain();
        }

        if ($this->riskAssessment !== null) {
            $ra = $this->riskAssessment;
            $lines[] = sprintf(
                'Risk: approved=%s qty=%.6f SL=%.2f TP=%.2f R:R=%.2f',
                $ra->approved ? 'yes' : 'no',
                $ra->positionSizeQuantity,
                $ra->stopLossPrice,
                $ra->takeProfitPrice,
                $ra->riskRewardRatio,
            );
            if ($ra->rejectionReason) {
                $lines[] = "Risk rejection: {$ra->rejectionReason}";
            }
        }

        if ($this->exitSignal !== null) {
            $lines[] = 'Exit signal: '.$this->exitSignal->explain();
        }

        return implode("\n", $lines);
    }
}
