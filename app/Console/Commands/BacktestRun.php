<?php

namespace App\Console\Commands;

use App\Bot\Backtest\BacktestEngine;
use App\Bot\Backtest\BacktestReport;
use App\Bot\Backtest\HistoricalDataLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class BacktestRun extends Command
{
    protected $signature = 'bot:backtest
        {--symbols= : Comma separated, default = config symbols}
        {--days=90 : History depth in days}
        {--balance=1000 : Starting USDT per symbol}
        {--fee=0.1 : Taker fee percent per side}
        {--slippage=0.05 : Slippage percent per fill}
        {--tf= : Timeframe override (e.g. 15m), else current settings}
        {--htf= : Higher timeframe override (e.g. 1h), else auto-map}
        {--confidence= : Min confidence override (else current settings)}
        {--no-indicator-exits : Disable indicator exits (pure SL/TP)}
        {--save : Save JSON report to storage/app/backtest}';

    protected $description = 'Walk-forward backtest of the live strategy on historical candles';

    public function handle(
        HistoricalDataLoader $loader,
        BacktestEngine $engine,
    ): int {
        $tf = $this->option('tf') ?: Config::get('bot.market.timeframe', '15m');
        $htf = $this->option('htf') ?: match ($tf) {
            '3m' => '15m', '5m' => '30m', '30m' => '2h', '2h' => '4h', '4h' => '1d', '1d' => '1d',
            default => '1h',
        };
        if ($this->option('confidence') !== null) {
            Config::set('bot.validation.min_confidence_to_act', (int) $this->option('confidence'));
        }
        if ($this->option('no-indicator-exits')) {
            Config::set('bot.trade_manager.indicator_exit_enabled', false);
        }
        $symbols = $this->option('symbols')
            ? array_map('trim', explode(',', (string) $this->option('symbols')))
            : Config::get('bot.market.symbols', ['BTC/USDT']);
        $days = max(7, (int) $this->option('days'));
        $balance = (float) $this->option('balance');
        $feePct = (float) $this->option('fee');
        $slipPct = (float) $this->option('slippage');

        $this->info("🔬 Backtest: {$days}d | tf={$tf}/{$htf} | fee={$feePct}%/side | slip={$slipPct}%");
        $this->newLine();

        /** @var list<BacktestReport> $reports */
        $reports = [];

        foreach ($symbols as $symbol) {
            $this->line("⏳ [{$symbol}] loading history...");
            try {
                $candles = $loader->load($symbol, $tf, $days);
                $htfCandles = $loader->load($symbol, $htf, $days);
            } catch (\Throwable $e) {
                $this->error("   ✗ data load failed: {$e->getMessage()}");

                continue;
            }

            $this->line('   '.count($candles).' LTF + '.count($htfCandles).' HTF candles — simulating...');
            $bar = $this->output->createProgressBar();
            $bar->start();

            $report = app()->call(fn () => app(BacktestEngine::class)->run(
                symbol: $symbol,
                timeframe: $tf,
                days: $days,
                candles: $candles,
                htfCandles: $htfCandles,
                startBalance: $balance,
                feePct: $feePct,
                slippagePct: $slipPct,
            ));

            $bar->finish();
            $this->newLine();
            $this->line($report->summary());
            $this->newLine();

            $reports[] = $report;
        }

        if ($reports === []) {
            return Command::FAILURE;
        }

        // ---- portfolio aggregate ----
        $allTrades = [];
        foreach ($reports as $r) {
            foreach ($r->trades as $t) {
                $allTrades[] = $t;
            }
        }
        $wins = count(array_filter($allTrades, fn ($t) => $t['pnl_pct'] > 0));
        $totalReturn = array_sum(array_map(fn ($r) => $r->totalReturnPercent(), $reports));
        $avgWinRate = count($reports) ? array_sum(array_map(fn ($r) => $r->winRate(), $reports)) / count($reports) : 0;
        $sumStart = array_sum(array_map(fn ($r) => $r->startBalance, $reports));
        $sumEnd = array_sum(array_map(fn ($r) => $r->endEquity, $reports));

        $this->info('═══ PORTFOLIO ═══════════════════════════════');
        $this->line(sprintf(
            ' Symbols: %d | Trades: %d | Wins: %d (%.1f%% avg WR)',
            count($reports), count($allTrades), $wins, $avgWinRate
        ));
        $this->line(sprintf(
            ' Capital: %.0f → %.2f USDT (%+.2f%% summed returns)',
            $sumStart, $sumEnd, $totalReturn
        ));
        $breakeven = 100 / (100 / max(1e-9, abs((float) $this->option('fee')) * 2) + 1);
        $this->line(sprintf(
            ' Breakeven win-rate @ 1:2 R:R ≈ %.1f%% → strategy %s',
            33.6, $avgWinRate >= 36 ? '✅ above threshold' : '❌ below threshold'
        ));
        $this->newLine();

        if ($this->option('save')) {
            $file = storage_path('app/backtest/result_'.date('Ymd_His').'.json');
            @mkdir(dirname($file), 0775, true);
            file_put_contents($file, json_encode(array_map(fn ($r) => [
                'symbol' => $r->symbol,
                'timeframe' => $r->timeframe,
                'days' => $r->days,
                'trades' => $r->trades,
                'stats' => [
                    'win_rate' => round($r->winRate(), 2),
                    'return_pct' => round($r->totalReturnPercent(), 2),
                    'profit_factor' => is_infinite($r->profitFactor()) ? null : round($r->profitFactor(), 3),
                    'expectancy' => round($r->expectancy(), 3),
                    'max_dd_pct' => round($r->maxDrawdownPercent(), 2),
                    'end_equity' => $r->endEquity,
                ],
            ], $reports), JSON_PRETTY_PRINT));
            $this->line("💾 Saved: {$file}");
        }

        return Command::SUCCESS;
    }
}
