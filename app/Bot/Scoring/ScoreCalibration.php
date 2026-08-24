<?php

namespace App\Bot\Scoring;

readonly class ScoreCalibration
{
    public function __construct(
        public float $trendSeparationScale = 50.0,
        public float $macdMagnitudeScale = 500.0,
        public float $atrPctDeadThreshold = 0.15,
        public float $atrPctExtremeThreshold = 8.0,
        public float $atrPctCriticalBlocker = 15.0,
        public float $atrPctIdealCenter = 2.0,
    ) {}
}
