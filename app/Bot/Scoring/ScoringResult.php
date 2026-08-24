<?php

namespace App\Bot\Scoring;

class ScoringResult
{
    public function __construct(
        public float $rawWeightedScore,
        public float $finalConfidence,
        public string $band,
        public array $factors,
        public array $criticalBlockers,
        public string $direction,  // "BUY", "NEUTRAL"
    ) {}

    public function explain(): string
    {
        $lines = [
            'Confidence: '.number_format($this->finalConfidence, 1)." ({$this->band}) | Direction: {$this->direction}",
        ];
        $lines[] = 'Positive/Negative factor breakdown:';

        foreach ($this->factors as $f) {
            $tag = $f->isCriticalBlocker ? ' [BLOCKER]' : '';
            $lines[] = "  - {$f->name}: ".number_format($f->score, 1).'/100 (weight '.
                number_format($f->weight, 2)."){$tag} {$f->explanation}";
        }

        if ($this->criticalBlockers !== []) {
            $lines[] = 'Critical blockers triggered: '.implode(', ', $this->criticalBlockers);
        }

        return implode("\n", $lines);
    }
}
