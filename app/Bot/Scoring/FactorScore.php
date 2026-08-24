<?php

namespace App\Bot\Scoring;

class FactorScore
{
    public function __construct(
        public string $name,
        public float $score,
        public float $weight = 0.0,
        public bool $isCriticalBlocker = false,
        public string $explanation = '',
    ) {}
}
