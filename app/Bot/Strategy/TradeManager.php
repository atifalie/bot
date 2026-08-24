<?php

namespace App\Bot\Strategy;

use App\Models\BotState;
use Illuminate\Support\Facades\Config;

/**
 * Trade Manager — DB-backed (bot_states table) instead of JSON file.
 * Port of strategy/trade_manager.py with improvements:
 * - Thread-safe via DB transactions
 * - Survives restarts, shared across workers
 */
class TradeManager
{
    protected const STATE_KEY = 'trade_manager_daily';

    public function __construct(
        protected int $maxDailyTrades = 8,
        protected int $minMinutesBetweenTrades = 5,
        protected float $dailyProfitTargetPercent = 3.0,
        protected float $dailyLossLimitPercent = 2.0,
    ) {}

    public function getMaxDailyTrades(): int
    {
        return $this->maxDailyTrades;
    }

    public function getMinMinutesBetweenTrades(): int
    {
        return $this->minMinutesBetweenTrades;
    }

    public function getDailyProfitTargetPercent(): float
    {
        return $this->dailyProfitTargetPercent;
    }

    public function getDailyLossLimitPercent(): float
    {
        return $this->dailyLossLimitPercent;
    }

    public static function fromConfig(): self
    {
        $cfg = Config::get('bot.trade_manager');

        return new self(
            maxDailyTrades: $cfg['max_daily_trades'] ?? 8,
            minMinutesBetweenTrades: $cfg['min_minutes_between_trades'] ?? 5,
            dailyProfitTargetPercent: $cfg['daily_profit_target_percent'] ?? 3.0,
            dailyLossLimitPercent: $cfg['daily_loss_limit_percent'] ?? 2.0,
        );
    }

    public function recordTrade(bool $won, float $pnlPercent, float $pnlUsdt = 0.0, string $symbol = ''): void
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        BotState::write(self::STATE_KEY, function ($state) use ($won, $pnlPercent, $pnlUsdt, $symbol, $now) {
            $data = $state ?? [
                'date' => $now->format('Y-m-d'),
                'trades' => [],
                'consecutive_wins' => 0,
                'consecutive_losses' => 0,
                'daily_pnl_usdt' => 0.0,
                'starting_balance_today' => 0.0,
            ];

            $this->maybeResetDaily($data, $now);

            $data['trades'][] = [
                'won' => $won,
                'pnl_pct' => $pnlPercent,
                'pnl_usdt' => $pnlUsdt,
                'time' => $now->format('c'),
                'symbol' => $symbol,
            ];
            $data['daily_pnl_usdt'] += $pnlUsdt;

            if ($won) {
                $data['consecutive_wins'] = ($data['consecutive_wins'] ?? 0) + 1;
                $data['consecutive_losses'] = 0;
            } else {
                $data['consecutive_losses'] = ($data['consecutive_losses'] ?? 0) + 1;
                $data['consecutive_wins'] = 0;
            }

            return $data;
        });
    }

    public function canTrade(string $symbol = ''): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $state = $this->getState($now);
        $data = $state['data'] ?? [];

        $this->maybeResetDaily($data, $now);

        // Rule 1: Max daily trades
        $tradesToday = count($data['trades'] ?? []);
        if ($tradesToday >= $this->maxDailyTrades) {
            return [false, "max_daily_trades_hit({$tradesToday}/{$this->maxDailyTrades})"];
        }

        // Rule 2: Min time between trades (per-symbol)
        $checkTime = null;
        if ($symbol !== '') {
            foreach (array_reverse($data['trades'] ?? []) as $t) {
                if (($t['symbol'] ?? '') === $symbol) {
                    $checkTime = new \DateTime($t['time']);
                    break;
                }
            }
        } else {
            $checkTime = $data['trades'] ? new \DateTime(end($data['trades'])['time']) : null;
        }

        if ($checkTime) {
            $elapsed = $now->getTimestamp() - $checkTime->getTimestamp();
            $elapsedMin = $elapsed / 60;
            if ($elapsedMin < $this->minMinutesBetweenTrades) {
                $remaining = $this->minMinutesBetweenTrades - $elapsedMin;

                return [false, 'cooldown('.round($remaining).'min_remaining)'];
            }
        }

        // Rule 3: Daily profit target
        $dailyPnlPct = $this->getDailyPnlPercent($data);
        if ($dailyPnlPct >= $this->dailyProfitTargetPercent) {
            return [false, sprintf('daily_target_hit(%.2f%%>=%.2f%%)', $dailyPnlPct, $this->dailyProfitTargetPercent)];
        }

        // Rule 4: Daily loss limit
        if ($dailyPnlPct <= -abs($this->dailyLossLimitPercent)) {
            return [false, sprintf('daily_loss_limit(%.2f%%)', $dailyPnlPct)];
        }

        return [true, ''];
    }

    public function getConfidenceModifier(): float
    {
        $state = $this->getState();
        $data = $state['data'] ?? [];

        $wins = $data['consecutive_wins'] ?? 0;
        $losses = $data['consecutive_losses'] ?? 0;

        if ($wins >= 3) {
            return 5.0;
        }
        if ($losses >= 5) {
            return -20.0;
        }
        if ($losses >= 3) {
            return -10.0;
        }

        return 0.0;
    }

    public function getPositionSizeMultiplier(): float
    {
        $state = $this->getState();
        $losses = ($state['data']['consecutive_losses'] ?? 0);

        if ($losses >= 5) {
            return 0.5;
        }
        if ($losses >= 3) {
            return 0.7;
        }

        return 1.0;
    }

    public function setStartingBalance(float $balance): void
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        BotState::write(self::STATE_KEY, function ($state) use ($balance, $now) {
            $data = $state ?? ['date' => $now->format('Y-m-d'), 'starting_balance_today' => 0.0];
            $this->maybeResetDaily($data, $now);
            if ($data['starting_balance_today'] == 0.0) {
                $data['starting_balance_today'] = $balance;
            }

            return $data;
        });
    }

    public function getDailyPnlPercent(): float
    {
        $state = $this->getState();
        $data = $state['data'] ?? [];
        $balance = $data['starting_balance_today'] ?? 0.0;
        if ($balance <= 0) {
            return 0.0;
        }
        $pnl = $data['daily_pnl_usdt'] ?? 0.0;

        return ($pnl / $balance) * 100;
    }

    public function statusSummary(): string
    {
        $state = $this->getState();
        $data = $state['data'] ?? [];
        $trades = count($data['trades'] ?? []);
        $wins = $data['consecutive_wins'] ?? 0;
        $losses = $data['consecutive_losses'] ?? 0;

        return "Trades today: {$trades}/{$this->maxDailyTrades} | Win streak: {$wins} | Loss streak: {$losses} | Daily PnL: ".number_format($this->getDailyPnlPercent(), 2).'%';
    }

    protected function getState(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime('now', new \DateTimeZone('UTC'));
        $state = BotState::read(self::STATE_KEY) ?? ['data' => ['date' => $now->format('Y-m-d')]];

        return ['data' => $state];
    }

    protected function maybeResetDaily(array &$data, \DateTimeInterface $now): void
    {
        $dateKey = $now->format('Y-m-d');
        if (($data['date'] ?? '') !== $dateKey) {
            $data = array_merge($data, [
                'date' => $dateKey,
                'trades' => [],
                'daily_pnl_usdt' => 0.0,
            ]);
            // Keep streaks across days (they reset on new trade recording)
        }
    }
}
