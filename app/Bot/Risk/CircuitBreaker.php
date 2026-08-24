<?php

namespace App\Bot\Risk;

use App\Models\BotState;

/**
 * Circuit Breaker — DB-backed (bot_states table) instead of JSON file.
 * Implements daily loss limit, consecutive loss cooldown, max drawdown from peak.
 * Port of risk/risk_manager.py CircuitBreaker.
 */
class CircuitBreaker
{
    protected const STATE_KEY = 'circuit_breaker_daily';

    public function __construct(
        protected float $maxDailyLossPercent = 5.0,
        protected int $maxConsecutiveLosses = 4,
        protected int $cooldownMinutesAfterMaxLosses = 120,
        protected float $maxDrawdownFromPeakPercent = 15.0,
    ) {}

    public static function fromConfig(): self
    {
        $cfg = config('bot.risk');

        return new self(
            maxDailyLossPercent: $cfg['max_daily_loss_percent'] ?? 5.0,
            maxConsecutiveLosses: $cfg['max_consecutive_losses'] ?? 4,
            cooldownMinutesAfterMaxLosses: $cfg['cooldown_minutes_after_max_losses'] ?? 120,
            maxDrawdownFromPeakPercent: $cfg['max_drawdown_from_peak_percent'] ?? 15.0,
        );
    }

    protected function load(): array
    {
        return BotState::read(self::STATE_KEY) ?? [
            'date' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
            'consecutive_losses' => 0,
            'cooldown_until' => null,
            'peak_balance' => 0.0,
            'trades_today' => [],
        ];
    }

    protected function save(array $data): void
    {
        BotState::write(self::STATE_KEY, $data);
    }

    protected function ensureToday(array &$data): void
    {
        $today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        if (($data['date'] ?? '') !== $today) {
            $data = array_merge($data, [
                'date' => $today,
                'trades_today' => [],
            ]);
        }
    }

    public function setPeakBalance(float $balance): void
    {
        $data = $this->load();
        if ($balance > ($data['peak_balance'] ?? 0.0)) {
            $data['peak_balance'] = $balance;
            $this->save($data);
        }
    }

    public function recordTrade(bool $won, float $pnlPercent): void
    {
        $data = $this->load();
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->ensureToday($data);

        $data['trades_today'][] = [
            'won' => $won,
            'pnl_percent' => $pnlPercent,
            'timestamp' => $now->format('c'),
        ];

        if ($won) {
            $data['consecutive_losses'] = 0;
        } else {
            $data['consecutive_losses'] = ($data['consecutive_losses'] ?? 0) + 1;
            if (($data['consecutive_losses'] ?? 0) >= $this->maxConsecutiveLosses) {
                $cooldown = (new \DateTime('now', new \DateTimeZone('UTC')))
                    ->modify("+{$this->cooldownMinutesAfterMaxLosses} minutes");
                $data['cooldown_until'] = $cooldown->format('c');
            }
        }

        $this->save($data);
    }

    public function isTradingAllowed(): array
    {
        $data = $this->load();
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        if (isset($data['cooldown_until']) && $data['cooldown_until']) {
            $cooldown = new \DateTime($data['cooldown_until']);
            if ($now < $cooldown) {
                return [false, 'cooldown_active_until_'.$cooldown->format('c')];
            }
        }

        $dailyPnl = $this->todaysPnlPercent($data);
        if ($dailyPnl <= -abs($this->maxDailyLossPercent)) {
            return [false, sprintf('daily_loss_limit_hit(%.2f%%)', $dailyPnl)];
        }

        return [true, ''];
    }

    public function isDrawdownOk(float $currentBalance): array
    {
        $data = $this->load();
        $peak = $data['peak_balance'] ?? 0.0;

        if ($peak <= 0) {
            return [true, ''];
        }

        if ($currentBalance > $peak) {
            $data['peak_balance'] = $currentBalance;
            $this->save($data);

            return [true, ''];
        }

        $drawdownPct = (($peak - $currentBalance) / $peak) * 100;
        if ($drawdownPct >= $this->maxDrawdownFromPeakPercent) {
            return [false, sprintf('max_drawdown_hit(%.1f%%>=%.1f%%)', $drawdownPct, $this->maxDrawdownFromPeakPercent)];
        }

        return [true, ''];
    }

    public function todaysPnlPercent(array $data): float
    {
        $today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $pnl = 0.0;
        foreach ($data['trades_today'] ?? [] as $t) {
            if (($t['timestamp'] ?? '') !== '') {
                $ts = new \DateTime($t['timestamp']);
                if ($ts->format('Y-m-d') === $today) {
                    $pnl += $t['pnl_percent'] ?? 0.0;
                }
            }
        }

        return $pnl;
    }

    public function getMaxDailyLossPercent(): float
    {
        return $this->maxDailyLossPercent;
    }

    public function getMaxConsecutiveLosses(): int
    {
        return $this->maxConsecutiveLosses;
    }

    public function getCooldownMinutesAfterMaxLosses(): int
    {
        return $this->cooldownMinutesAfterMaxLosses;
    }

    public function getMaxDrawdownFromPeakPercent(): float
    {
        return $this->maxDrawdownFromPeakPercent;
    }
}
