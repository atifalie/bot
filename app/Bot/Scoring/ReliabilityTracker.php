<?php

namespace App\Bot\Scoring;

use App\Models\BotState;

/**
 * Rolling win-rate per direction — DB-backed (bot_states table) instead of
 * JSON files. Matches Python ReliabilityTracker interface.
 */
class ReliabilityTracker
{
    protected const MAX_HISTORY = 500;

    public function __construct(
        protected string $direction, // "BUY" or "SELL"
    ) {}

    public static function forDirection(string $direction): self
    {
        return new self($direction);
    }

    protected function stateKey(): string
    {
        return 'reliability_'.strtolower($this->direction);
    }

    public function recordOutcome(TradeOutcome $outcome): void
    {
        $history = $this->getHistory();
        $history[] = $outcome->toArray();

        // Trim to max_history
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        BotState::write($this->stateKey(), ['history' => $history]);
    }

    public function getWinRate(string $direction): array
    {
        if ($direction !== $this->direction) {
            return [0.5, 0];
        }

        $history = $this->getHistory();

        if ($history === []) {
            return [0.5, 0];
        }

        $wins = 0;
        foreach ($history as $h) {
            if (($h['won'] ?? false) === true) {
                $wins++;
            }
        }

        return [$wins / count($history), count($history)];
    }

    public function getCalibrationReport(array $confidenceBands): array
    {
        $history = $this->getHistory();
        $report = [];

        foreach ($confidenceBands as $bandName => $range) {
            $low = $range[0];
            $high = $range[1];

            $relevant = array_filter($history, fn ($h) => ($h['confidence'] ?? 0) >= $low && ($h['confidence'] ?? 0) < $high
            );

            if ($relevant === []) {
                $report[$bandName] = ['samples' => 0, 'actual_win_rate' => null];

                continue;
            }

            $wins = array_reduce($relevant, fn ($c, $h) => $c + (($h['won'] ?? false) ? 1 : 0), 0);
            $report[$bandName] = [
                'samples' => count($relevant),
                'actual_win_rate' => $wins / count($relevant),
            ];
        }

        return $report;
    }

    protected function getHistory(): array
    {
        $data = BotState::read($this->stateKey());

        if ($data === null || ! isset($data['history'])) {
            return [];
        }

        return is_array($data['history']) ? $data['history'] : [];
    }
}
