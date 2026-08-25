<?php

namespace App\Console\Commands;

use App\Bot\Backtest\BacktestEngine;
use App\Bot\Backtest\HistoricalDataLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * P0 #1 — Walk-forward, per-symbol, per-confidence-band edge discovery.
 *
 * Phase A: har symbol × har confidence-band ka full-period backtest
 *          (monthly PnL buckets ke sath).
 * Phase B: walk-forward — 3 mahine train pe best symbol/band chuno,
 *          agle mahine TEST karo, slide karo. Sirf unseen data pe score.
 *
 * Output: storage/app/backtest/walkforward_<ts>.md
 */
class WalkForwardRun extends Command
{
    protected $signature = 'bot:walkforward
        {--days=120 : History depth}
        {--bands=65,75 : Comma-separated min-confidence levels to compare}
        {--top=8 : How many symbols to select per walk-forward window}
        {--train=3 : Training window length in months}
        {--balance=1000 : Starting USDT per symbol-run}
        {--fee=0.1 : Taker fee % per side}
        {--slippage=0.05 : Slippage % per fill}
        {--tf= : Timeframe override (default = live config)}
        {--htf= : Higher timeframe override (default = live config)}
        {--no-indicator-exits : Disable indicator exits (pure SL/TP + trailing)}
        {--symbols= : Override comma-separated symbol list}';

    protected $description = 'Per-symbol × per-band backtests + true walk-forward selection test';

    public function handle(
        HistoricalDataLoader $loader,
        BacktestEngine $engine,
    ): int {
        $days = max(60, (int) $this->option('days'));
        $bands = array_map('intval', array_filter(explode(',', (string) $this->option('bands'))));
        $topK = max(1, (int) $this->option('top'));
        $trainMonths = max(2, (int) $this->option('train'));
        $balance = (float) $this->option('balance');
        $feePct = (float) $this->option('fee');
        $slipPct = (float) $this->option('slippage');

        // Live bot 5m/15m chal raha hai — backtest bhi wahi combine use kare
        $tf = $this->option('tf') ?: Config::get('bot.market.timeframe', '5m');
        $htf = $this->option('htf') ?: Config::get('bot.market.higher_timeframe', '15m');

        if ($this->option('no-indicator-exits')) {
            Config::set('bot.trade_manager.indicator_exit_enabled', false);
        }

        $symbols = $this->option('symbols')
            ? array_map('trim', explode(',', (string) $this->option('symbols')))
            : Config::get('bot.market.symbols', ['BTC/USDT']);

        $originalConf = Config::get('bot.validation.min_confidence_to_act');

        $this->info("🔬 Walk-forward study: {$days}d | tf={$tf}/{$htf} | bands=".implode('/', $bands)." | top={$topK}");
        $this->line(' Symbols: '.count($symbols));

        // ──────────────────────────── PHASE A ────────────────────────────
        // runs[symbol][band] = ['trades'=>[], 'stats'=>[], 'monthly'=>[ym=>['sum'=>,'n'=>,'wins'=>]]]
        $runs = [];
        $done = 0;
        $totalRuns = count($symbols) * count($bands);

        foreach ($symbols as $symbol) {
            try {
                $candles = $loader->load($symbol, $tf, $days);
                $htfCandles = $loader->load($symbol, $htf, $days);
            } catch (\Throwable $e) {
                $this->warn(" ⨯ [{$symbol}] data unavailable: {$e->getMessage()}");

                continue;
            }

            foreach ($bands as $band) {
                Config::set('bot.validation.min_confidence_to_act', $band);

                $started = microtime(true);
                try {
                    $report = $engine->run(
                        symbol: $symbol,
                        timeframe: $tf,
                        days: $days,
                        candles: $candles,
                        htfCandles: $htfCandles,
                        startBalance: $balance,
                        feePct: $feePct,
                        slippagePct: $slipPct,
                    );
                } catch (\Throwable $e) {
                    $this->warn(" ⨯ [{$symbol}@{$band}] run failed: {$e->getMessage()}");

                    continue;
                }

                $monthly = [];
                foreach ($report->trades as $t) {
                    $ym = gmdate('Y-m', (int) (((int) $t['entry_ts']) / 1000));
                    $monthly[$ym]['sum'] = ($monthly[$ym]['sum'] ?? 0) + $t['pnl_pct'];
                    $monthly[$ym]['n'] = ($monthly[$ym]['n'] ?? 0) + 1;
                    $monthly[$ym]['wins'] = ($monthly[$ym]['wins'] ?? 0) + ($t['pnl_pct'] > 0 ? 1 : 0);
                }
                ksort($monthly);

                $runs[$symbol][$band] = [
                    'n' => count($report->trades),
                    'wr' => $report->winRate(),
                    'ret' => $report->totalReturnPercent(),
                    'exp' => $report->expectancy(),
                    'dd' => $report->maxDrawdownPercent(),
                    'pf' => $report->profitFactor(),
                    'monthly' => $monthly,
                ];

                $done++;
                $this->line(sprintf(
                    ' [%2d/%2d] %-14s conf=%d → n=%3d WR=%4.1f%% ret=%+6.2f%% DD=%5.1f%% (%.0fs)',
                    $done, $totalRuns, $symbol, $band,
                    count($report->trades), $report->winRate(),
                    $report->totalReturnPercent(), $report->maxDrawdownPercent(),
                    microtime(true) - $started,
                ));
            }
        }

        Config::set('bot.validation.min_confidence_to_act', $originalConf);

        if ($runs === []) {
            $this->error('No successful runs — aborting.');

            return Command::FAILURE;
        }

        // ──────────────────────────── PHASE B ────────────────────────────
        // Walk-forward: train N months → pick top-K (symbol,band) pairs →
        // test next month → slide. Selection sirf TRAIN data pe, score TEST pe.
        $months = [];
        foreach ($runs as $sym => $byBand) {
            foreach ($byBand as $band => $r) {
                foreach ($r['monthly'] as $ym => $m) {
                    if ($m['n'] >= 3) { // kam-se-kam 3 trades warna month skip
                        $months[$ym] = true;
                    }
                }
            }
        }
        $months = array_keys($months);
        sort($months);

        $wfRows = [];
        $wfEquity = 100.0;
        $baseEquity = 100.0;
        $picks = [];

        for ($i = $trainMonths; $i < count($months); $i++) {
            $trainSet = array_slice($months, $i - $trainMonths, $trainMonths);
            $testMonth = $months[$i];

            // Rank pairs by train-window total return
            $ranked = [];
            foreach ($runs as $sym => $byBand) {
                foreach ($byBand as $band => $r) {
                    $trainSum = 0.0;
                    $trainN = 0;
                    foreach ($trainSet as $ym) {
                        $trainSum += $r['monthly'][$ym]['sum'] ?? 0.0;
                        $trainN += $r['monthly'][$ym]['n'] ?? 0;
                    }
                    if ($trainN >= $trainMonths * 3) {
                        $ranked[] = ['sym' => $sym, 'band' => $band, 'train_ret' => $trainSum];
                    }
                }
            }
            usort($ranked, fn ($a, $b) => $b['train_ret'] <=> $a['train_ret']);

            // Top-K pairs — ek symbol sirf ek baar (uska best band)
            $selected = [];
            $seenSym = [];
            foreach ($ranked as $pair) {
                if (isset($seenSym[$pair['sym']])) {
                    continue;
                }
                $seenSym[$pair['sym']] = true;
                $selected[] = $pair;
                if (count($selected) >= $topK) {
                    break;
                }
            }

            // Test month performance of selected pairs (unseen data)
            $testSum = 0.0;
            $testBaseSum = 0.0;
            $baseCount = 0;
            foreach ($selected as $sel) {
                $r = $runs[$sel['sym']][$sel['band']];
                $testSum += $r['monthly'][$testMonth]['sum'] ?? 0.0;
                $picks[] = $sel['sym'].'@'.$sel['band'];
            }
            foreach ($runs as $sym => $byBand) {
                foreach ($byBand as $band => $r) {
                    if (($r['monthly'][$testMonth]['n'] ?? 0) >= 3) {
                        $testBaseSum += $r['monthly'][$testMonth]['sum'];
                        $baseCount++;
                    }
                }
            }

            $avgTest = $topK > 0 ? $testSum / $topK : 0.0;
            $avgBase = $baseCount > 0 ? $testBaseSum / $baseCount : 0.0;
            $wfEquity *= (1 + $avgTest / 100);
            $baseEquity *= (1 + $avgBase / 100);

            $wfRows[] = [
                'test' => $testMonth,
                'train' => implode(',', $trainSet),
                'sel' => implode(', ', array_map(fn ($s) => $s['sym'].'@'.$s['band'], $selected)),
                'avg_test' => $avgTest,
                'avg_base' => $avgBase,
                'equity' => $wfEquity,
            ];
        }

        // ──────────────────────────── REPORT ────────────────────────────
        $md = $this->buildReport($runs, $wfRows, $picks, [
            'days' => $days,
            'bands' => $bands,
            'topK' => $topK,
            'trainMonths' => $trainMonths,
            'feePct' => $feePct,
            'slippagePct' => $slipPct,
        ], $baseEquity);
        $file = storage_path('app/backtest/walkforward_'.date('Ymd_His').'.md');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $md);

        $this->newLine();
        $this->info("📄 Full report: {$file}");

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $runs
     * @param  list<array<string, mixed>>  $wfRows
     */
    protected function buildReport(array $runs, array $wfRows, array $picks, array $cfg, float $baseEquity): string
    {
        $out = "# Walk-Forward Edge Study\n\n";
        $out .= '- Generated: '.now()->toDateTimeString()." UTC\n";
        $out .= "- Depth: {$cfg['days']}d | Bands: ".implode('/', $cfg['bands']);
        $out .= " | Top-K: {$cfg['topK']} | Train: {$cfg['trainMonths']}mo";
        $out .= " | Fee {$cfg['feePct']}%/side + slip {$cfg['slippagePct']}%\n\n";

        // ---- Section 1: per-symbol best band table ----
        $out .= "## 1) Per-symbol results (best band by return)\n\n";
        $out .= "| Symbol | Band | Trades | WinRate | Return% | Expectancy/trade | MaxDD% | PF |\n|---|---|---|---|---|---|---|---|\n";

        $rows = [];
        foreach ($runs as $sym => $byBand) {
            $bestBand = null;
            $bestRet = -INF;
            foreach ($byBand as $band => $r) {
                if ($r['ret'] > $bestRet) {
                    $bestRet = $r['ret'];
                    $bestBand = $band;
                }
            }
            $r = $byBand[$bestBand];
            $pf = is_infinite($r['pf']) ? '∞' : (string) round($r['pf'], 2);
            $rows[] = [$sym, $bestBand, $r['n'], $r['wr'], $r['ret'], $r['exp'], $r['dd'], $pf];
        }
        usort($rows, fn ($a, $b) => $b[4] <=> $a[4]);
        foreach ($rows as $row) {
            $out .= sprintf(
                "| %s | %d | %d | %.1f%% | %+0.2f%% | %+0.3f%% | %.1f | %s |\n",
                ...$row,
            );
        }

        // ---- Section 2: band aggregate comparison ----
        $out .= "\n## 2) Confidence-band comparison (aggregate over all symbols)\n\n";
        $out .= "| Band | Runs | Avg Return% | Avg WinRate | Median Return% |\n|---|---|---|---|---|\n";
        foreach ($cfg['bands'] as $band) {
            $rets = [];
            $wrs = [];
            foreach ($runs as $byBand) {
                if (isset($byBand[$band])) {
                    $rets[] = $byBand[$band]['ret'];
                    $wrs[] = $byBand[$band]['wr'];
                }
            }
            if ($rets === []) {
                continue;
            }
            sort($rets);
            $mid = $rets[intdiv(count($rets), 2)];
            $out .= sprintf(
                "| %d | %d | %+0.2f%% | %.1f%% | %+0.2f%% |\n",
                $band, count($rets), array_sum($rets) / count($rets),
                array_sum($wrs) / count($wrs), $mid,
            );
        }

        // ---- Section 3: walk-forward ----
        $finalWf = end($wfRows)['equity'] ?: 100.0;
        $out .= "\n## 3) TRUE walk-forward (selection on train → score on unseen test month)\n\n";
        $out .= "Baseline = ALL symbols avg | Strategy = top-{$cfg['topK']} selected on train window\n\n";
        $out .= "| Test Month | Selected (top) | Strategy % | Baseline % | WF Equity |\n|---|---|---|---|---|\n";
        foreach ($wfRows as $w) {
            $selShort = mb_strlen((string) $w['sel']) > 46 ? mb_substr((string) $w['sel'], 0, 43).'…' : $w['sel'];
            $out .= sprintf(
                "| %s | %s | %+0.2f%% | %+0.2f%% | %.1f |\n",
                $w['test'], $selShort, $w['avg_test'], $w['avg_base'], $w['equity'],
            );
        }
        $out .= sprintf("\n**Walk-forward equity: %.1f → %.1f (%+.1f%%)** | Baseline all-symbols: %.1f\n", 100.0, $finalWf, $finalWf - 100, $baseEquity);

        $posMonths = count(array_filter($wfRows, fn ($w) => $w['avg_test'] > 0));
        $out .= sprintf('Strategy positive in %d/%d test months. ', $posMonths, count($wfRows));

        // Verdict
        $out .= "\n## 4) VERDICT\n\n";
        if ($finalWf > $baseEquity && $finalWf > 100 && $posMonths >= ceil(count($wfRows) * 0.5)) {
            $out .= "✅ EDGE CONFIRMED — selected symbols outperform baseline on unseen months.\n";
            $out .= "→ Whitelist top performers + set MIN_CONFIDENCE to winning band.\n";
        } elseif ($finalWf > $baseEquity) {
            $out .= "🟡 WEAK EDGE — beats baseline but absolute profit unproven. Tighten filters before real money.\n";
        } else {
            $out .= "❌ NO PROVEN EDGE — selection fails on unseen data. Do NOT go live with real money.\n";
        }

        $out .= "\nPicked pairs frequency: ".implode(', ', array_count_values($picks) ? array_map(
            fn ($k, $v) => "{$k}×{$v}",
            array_keys(array_count_values($picks)), array_values(array_count_values($picks)),
        ) : ['—'])."\n";

        return $out;
    }
}
