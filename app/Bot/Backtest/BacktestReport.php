<?php

namespace App\Bot\Backtest;

/**
 * Aggregated results + stats for one backtest run (single symbol).
 */
class BacktestReport
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly int $days,
        /** @var list<array{symbol:string,entry_ts:int,exit_ts:int,entry:float,exit:float,qty:float,pnl_pct:float,equity:float,reason:string,confidence:float}> */
        public readonly array $trades,
        public readonly float $startBalance,
        public readonly float $endEquity,
    ) {}

    public function winRate(): float
    {
        if ($this->trades === []) {
            return 0.0;
        }

        return ($this->wins() / count($this->trades)) * 100;
    }

    public function wins(): int
    {
        return count(array_filter($this->trades, fn ($t) => $t['pnl_pct'] > 0));
    }

    public function losses(): int
    {
        return count($this->trades) - $this->wins();
    }

    public function totalReturnPercent(): float
    {
        if ($this->startBalance <= 0) {
            return 0.0;
        }

        return (($this->endEquity - $this->startBalance) / $this->startBalance) * 100;
    }

    public function profitFactor(): float
    {
        $grossWin = array_sum(array_map(fn ($t) => max(0, $t['pnl_pct']), $this->trades));
        $grossLoss = abs(array_sum(array_map(fn ($t) => min(0, $t['pnl_pct']), $this->trades)));

        return $grossLoss > 0 ? $grossWin / $grossLoss : ($grossWin > 0 ? INF : 0.0);
    }

    public function expectancy(): float
    {
        if ($this->trades === []) {
            return 0.0;
        }

        return array_sum(array_column($this->trades, 'pnl_pct')) / count($this->trades);
    }

    public function avgWin(): float
    {
        $w = array_values(array_filter(array_column($this->trades, 'pnl_pct'), fn ($p) => $p > 0));

        return $w !== [] ? array_sum($w) / count($w) : 0.0;
    }

    public function avgLoss(): float
    {
        $l = array_values(array_filter(array_column($this->trades, 'pnl_pct'), fn ($p) => $p < 0));

        return $l !== [] ? array_sum($l) / count($l) : 0.0;
    }

    public function maxDrawdownPercent(): float
    {
        // Intra-trade equity path: mark-to-market using each candle would be
        // heavier; trade-close equity is the standard v1 approximation.
        $peak = $this->startBalance;
        $maxDd = 0.0;

        foreach ($this->trades as $t) {
            $peak = max($peak, $t['equity']);
            if ($peak > 0) {
                $dd = (($peak - $t['equity']) / $peak) * 100;
                $maxDd = max($maxDd, $dd);
            }
        }

        return $maxDd;
    }

    /** @return array<string,int> exit reason => count */
    public function exitBreakdown(): array
    {
        $out = [];
        foreach ($this->trades as $t) {
            $key = preg_replace('/:.*/', '', $t['reason']) ?: 'unknown';
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    public function summary(): string
    {
        $pf = $this->profitFactor();

        $lines = [
            sprintf('── %s (%s, %dd) ─────────────────────────────', $this->symbol, $this->timeframe, $this->days),
            sprintf(' Trades: %-4d Win: %-3d Loss: %-3d WinRate: %.1f%%', count($this->trades), $this->wins(), $this->losses(), $this->winRate()),
            sprintf(' Return: %+.2f%%   End equity: %.2f → %.2f USDT', $this->totalReturnPercent(), $this->startBalance, $this->endEquity),
            sprintf(' PF: %s   Expectancy: %+.2f%%/trade   MaxDD: %.1f%%', is_infinite($pf) ? '∞' : number_format($pf, 2), $this->expectancy(), $this->maxDrawdownPercent()),
            sprintf(' AvgWin: %+.2f%%   AvgLoss: %+.2f%%   R:R realized: %s', $this->avgWin(), $this->avgLoss(), $this->realizedRR()),
            sprintf(' Net PnL: %+.2f USDT (fees included)   Avg deployed: %.2f/trade', $this->endEquity - $this->startBalance, $this->avgNotional()),
        ];

        foreach ($this->exitBreakdown() as $reason => $count) {
            $lines[] = sprintf('   • %-22s ×%d', $reason, $count);
        }

        return implode(PHP_EOL, $lines);
    }

    protected function realizedRR(): string
    {
        $w = $this->avgWin();
        $l = abs($this->avgLoss());

        return $l > 0 ? number_format($w / $l, 2) : '—';
    }

    protected function avgNotional(): float
    {
        if ($this->trades === []) {
            return 0.0;
        }

        return array_sum(array_map(fn ($t) => $t['entry'] * $t['qty'], $this->trades)) / count($this->trades);
    }
}
