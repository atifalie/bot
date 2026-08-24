<?php

namespace App\Bot\Risk;

readonly class RiskAssessment
{
    public function __construct(
        public bool $approved,
        public float $positionSizeQuantity,
        public float $stopLossPrice,
        public float $takeProfitPrice,
        public float $riskRewardRatio,
        public float $riskAmountUsdt,
        public ?string $rejectionReason = null,
    ) {}
}
