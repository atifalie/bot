<?php

namespace App\Bot\Trading;

use App\Models\BotState;

/**
 * State Store — DB-backed per-symbol position state (replaces JSON files).
 * Port of bot/state_manager.py — uses BotState model (bot_states table).
 */
class StateStore
{
    protected const STATE_PREFIX = 'position_state_';

    protected const RELIABILITY_PREFIX = 'reliability_';

    public function __construct(
        protected string $baseStateKey = 'position_state',
        protected string $reliabilityKey = 'reliability',
    ) {}

    protected function symbolKey(string $symbol): string
    {
        $suffix = str_replace('/', '_', $symbol);

        return $this->baseStateKey.'_'.$suffix;
    }

    protected function reliabilityKey(string $symbol): string
    {
        $suffix = str_replace('/', '_', $symbol);

        return $this->reliabilityKey.'_'.$suffix;
    }

    public function loadPosition(string $symbol): array
    {
        $data = BotState::read($this->symbolKey($symbol));

        if ($data === null) {
            return $this->defaultPosition();
        }

        return array_merge($this->defaultPosition(), $data);
    }

    public function savePosition(string $symbol, array $state): void
    {
        $key = $this->symbolKey($symbol);
        $clean = array_filter($state, fn ($v) => $v !== null && $v !== '');
        BotState::write($key, $clean);
    }

    public function deletePosition(string $symbol): void
    {
        BotState::remove($this->symbolKey($symbol));
    }

    public function loadReliability(string $symbol): array
    {
        $data = BotState::read($this->reliabilityKey($symbol));

        return $data['history'] ?? [];
    }

    public function saveReliability(string $symbol, array $history): void
    {
        BotState::write($this->reliabilityKey($symbol), ['history' => $history]);
    }

    protected function defaultPosition(): array
    {
        return [
            'in_position' => false,
            'entry_price' => null,
            'quantity' => null,
            'stop_loss' => null,
            'take_profit' => null,
            'direction' => null,
            'confidence' => null,
            'pending_entry' => null, // for order recovery
        ];
    }
}
