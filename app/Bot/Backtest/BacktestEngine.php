<?php

namespace App\Bot\Backtest;

use App\Bot\Features\FeatureSet;
use App\Bot\Features\Indicators;
use App\Bot\MarketData\CandleSeries;
use App\Bot\MarketData\DataQualityReport;
use App\Bot\Risk\RiskAssessor;
use App\Bot\Strategy\Decision;
use App\Bot\Strategy\DecisionEngine;
use App\Bot\Strategy\TrailingStop;
use Illuminate\Support\Facades\Config;

/**
 * Walk-forward backtester that reuses the LIVE decision pipeline
 * (DecisionEngine → ScoringEngine → gates) untouched — same code that
 * trades real money decides in simulation.
 *
 * Fill model (candle-close granularity, pessimistic):
 *  - Entries/exits at candle close ± slippage
 *  - Intra-candle SL checked BEFORE TP (worst-case assumption)
 *  - Trailing stop updated on close, mirrors live updateTrailingStop()
 */
class BacktestEngine
{
    protected const WINDOW = 300;

    protected string $symbol = '';

    public function __construct(
        protected DecisionEngine $decisionEngine,
        protected RiskAssessor $riskAssessor,
    ) {}

    /**
     * @param  list<array{0:int,1:float,2:float,3:float,4:float,5:float}>  $candles  LTF (15m) ascending
     * @param  list<array{0:int,1:float,2:float,3:float,4:float,5:float}>  $htfCandles  HTF (1h) ascending
     */
    public function run(
        string $symbol,
        string $timeframe,
        int $days,
        array $candles,
        array $htfCandles,
        float $startBalance = 1000.0,
        float $feePct = 0.1,
        float $slippagePct = 0.05,
    ): BacktestReport {
        $indCfg = Config::get('bot.indicators');
        $trailMult = Config::get('bot.risk.atr_stop_loss_multiplier', 2.0) * 0.75;
        $tpMult = Config::get('bot.risk.atr_take_profit_multiplier', 4.0);
        $minHoldHours = (float) Config::get('bot.trade_manager.indicator_exit_min_hold_hours', 6.0);
        $dailyLossLimit = abs((float) Config::get('bot.trade_manager.daily_loss_limit_percent', 10.0));
        $cb = $this->inMemoryCircuitBreaker();
        $this->symbol = $symbol;

        $equity = $startBalance;
        $position = null;
        /** @var list<array> $trades */
        $trades = [];
        $dayKey = '';
        $dayStartEquity = $startBalance;
        $dayBlocked = false;

        $htfCache = [];
        $total = count($candles);
        $skip = min(self::WINDOW + 20, max(150, (int) ($total * 0.05)));

        for ($i = $skip; $i < $total; $i++) {
            $candle = $candles[$i];
            $ts = (int) $candle[0];

            // ---- daily loss-limit guard (mirrors live TradeManager rule) ----
            $key = gmdate('Y-m-d', (int) ($ts / 1000));
            if ($key !== $dayKey) {
                $dayKey = $key;
                $dayStartEquity = $equity;
                $dayBlocked = false;
            }

            $window = array_slice($candles, $i - self::WINDOW + 1, self::WINDOW);
            $series = CandleSeries::fromRaw($window);
            $report = new DataQualityReport(true, count($window), 0.0, 0, 0);

            // ---- position management first (fills happen intra-candle) ----
            if ($position !== null) {
                $hitSl = $candle[3] <= $position->stopLoss;   // low
                $hitTp = $candle[2] >= $position->takeProfit; // high

                if ($hitSl) { // pessimistic: SL wins ties
                    [$equity, $trade, $position] = $this->close($position, $position->stopLoss * (1 - $slippagePct / 100), $ts, $equity, $feePct, 'stop_loss');
                    $trades[] = $trade;
                    $dayBlocked = ($equity - $dayStartEquity) / max(1e-9, $dayStartEquity) * 100 <= -$dailyLossLimit;

                    continue;
                }

                if ($hitTp) {
                    [$equity, $trade, $position] = $this->close($position, $position->takeProfit * (1 - $slippagePct / 100), $ts, $equity, $feePct, 'take_profit');
                    $trades[] = $trade;
                    $dayBlocked = false;

                    continue;
                }
            }

            // ---- trailing stop maintenance on HOLD ----
            if ($position !== null) {
                $features = Indicators::computeAll($series, $indCfg);
                $atr = $features->last('atr') ?? 0.0;
                $close = (float) $candle[4];

                if ($atr > 0 && $close > $position->entryPrice) {
                    // Dynamic lock = 60% of TP distance (mirrors live fix)
                    $tpDistPct = ($tpMult * $atr / max(1e-9, $position->entryPrice)) * 100;
                    $lockPct = max(0.3, round($tpDistPct * 0.6, 2));

                    $newSl = TrailingStop::update(
                        entryPrice: $position->entryPrice,
                        currentPrice: $close,
                        originalStopLoss: $position->stopLoss,
                        atr: $atr,
                        trailMultiplier: $trailMult,
                        minProfitLockPct: $lockPct,
                    );
                    if ($newSl > $position->stopLoss) {
                        $position = $position->withStopLoss($newSl);
                    }
                }

                // indicator exit at close (respects min-hold like live)
                $heldH = ($ts - $position->entryTs) / 3_600_000;
                if ($heldH >= $minHoldHours) {
                    $decision = $this->decide($series, $report, $equity, true, $cb, $this->htfFeatures($htfCandles, $ts, $indCfg, $htfCache));
                    if ($decision->action === Decision::EXIT) {
                        [$equity, $trade, $position] = $this->close($position, $close * (1 - $slippagePct / 100), $ts, $equity, $feePct, substr($decision->reason, 0, 40));
                        $trades[] = $trade;

                        continue;
                    }
                }

                continue;
            }

            // ---- flat: entry logic ----
            if ($dayBlocked || count($trades) > 0 && end($trades)['exit_ts'] > $ts - 5 * 60_000) {
                continue; // daily guard / cooldown between trades
            }

            $decision = $this->decide($series, $report, $equity, false, $cb, $this->htfFeatures($htfCandles, $ts, $indCfg, $htfCache));
            if ($decision->action !== Decision::BUY) {
                continue;
            }

            $fill = (float) $candle[4] * (1 + $slippagePct / 100);
            $features = Indicators::computeAll($series, $indCfg);
            $atr = $features->last('atr') ?? 0.0;
            if ($atr <= 0) {
                continue;
            }

            $assessment = $this->riskAssessor->assess($fill, $atr, $equity);
            if (! $assessment->approved || $assessment->positionSizeQuantity <= 0) {
                continue;
            }

            $position = new SimPosition(
                entryPrice: $fill,
                quantity: $assessment->positionSizeQuantity,
                stopLoss: $assessment->stopLossPrice,
                takeProfit: $assessment->takeProfitPrice,
                entryTs: $ts,
                entryIndex: $i,
                confidence: $decision->confidence,
            );
        }

        return new BacktestReport($symbol, $timeframe, $days, $trades, $startBalance, $equity);
    }

    /**
     * @return array{0: float, 1: array, 2: null}
     */
    protected function close(SimPosition $p, float $exitPrice, int $exitTs, float $equity, float $feePct, string $reason): array
    {
        $pnlUsdt = ($exitPrice - $p->entryPrice) * $p->quantity;
        $fees = ($p->entryPrice * $p->quantity + $exitPrice * $p->quantity) * $feePct / 100;
        $netEquity = $equity + $pnlUsdt - $fees;

        $trade = [
            'symbol' => $this->symbol,
            'entry_ts' => $p->entryTs,
            'exit_ts' => $exitTs,
            'entry' => $p->entryPrice,
            'exit' => round($exitPrice, 8),
            'qty' => $p->quantity,
            'pnl_pct' => round($p->pnlPercent($exitPrice, $feePct), 3),
            'equity' => round($netEquity, 2),
            'reason' => $reason,
            'confidence' => $p->confidence,
        ];

        return [$netEquity, $trade, null];
    }

    protected function decide(CandleSeries $series, DataQualityReport $report, float $balance, bool $inPosition, object $cb, ?FeatureSet $htf): Decision
    {
        return $this->decisionEngine->makeDecision(
            series: $series,
            qualityReport: $report,
            usdtBalance: $balance,
            inPosition: $inPosition,
            circuitBreaker: $cb,
            htfFeatures: $htf,
            reliabilityTracker: null,
        );
    }

    /** HTF features change hourly — compute once per hour bucket, not per 15m candle. */
    protected function htfFeatures(array $htf, int $ts, array $indCfg, array &$cache): ?FeatureSet
    {
        $bucket = (int) floor($ts / 3_600_000); // hour bucket

        if (array_key_exists($bucket, $cache)) {
            return $cache[$bucket];
        }

        // Only FULLY CLOSED HTF candles (close time <= now) — no lookahead
        $slice = array_values(array_filter($htf, fn ($r) => (int) $r[0] + 3_600_000 <= $ts));
        $slice = array_slice($slice, -200);

        $out = null;
        if (count($slice) >= 150) {
            $out = Indicators::computeAll(CandleSeries::fromRaw($slice), $indCfg);
        }

        return $cache[$bucket] = $out;
    }

    protected function inMemoryCircuitBreaker(): object
    {
        return new class
        {
            public function isTradingAllowed(): array
            {
                return [true, ''];
            }
        };
    }
}
