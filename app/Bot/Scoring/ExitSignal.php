<?php

namespace App\Bot\Scoring;

readonly class ExitSignal
{
    public function __construct(
        public bool $shouldExit,
        public string $reason,      // "bearish_crossover", "rsi_overbought", "bb_upper_touch", "vwap_overextended", "stoch_rsi_bearish_cross", "hold"
        public float $strength,     // 0-100
        public array $allSignals = [],
    ) {}

    public function explain(): string
    {
        if (! $this->shouldExit) {
            return 'Hold position (no exit signal)';
        }

        return sprintf(
            'EXIT: %s (strength %.1f/100)%s',
            $this->reason,
            $this->strength,
            $this->allSignals !== [] ? ' | all: '.implode(', ', $this->allSignals) : '',
        );
    }
}
