<?php

namespace App\Bot\Trading;

use App\Bot\Exchange\Trader;
use App\Bot\Features\Indicators;
use App\Bot\MarketData\CandleProviderInterface;
use App\Bot\MarketData\CandleSeries;
use App\Bot\Monitoring\DecisionLogger;
use App\Bot\Monitoring\MailNotifier;
use App\Bot\Risk\CircuitBreaker;
use App\Bot\Risk\RiskAssessor;
use App\Bot\Scoring\ReliabilityTracker;
use App\Bot\Strategy\Decision;
use App\Bot\Strategy\DecisionEngine;
use App\Bot\Strategy\TrailingStop;
use App\Models\Trade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * The per-symbol decision pipeline shared by both run modes:
 *  - bot:run   (REST candles, scheduler-driven)
 *  - bot:daemon (WS-fed streaming candles, event-driven)
 *
 * Moved verbatim out of BotRun so behaviour is identical in either mode.
 */
class SymbolCycleProcessor
{
    public function __construct(
        protected Trader $trader,
        protected DecisionEngine $decisionEngine,
        protected RiskAssessor $riskAssessor,
        protected CircuitBreaker $circuitBreaker,
        protected StateStore $stateStore,
        protected DecisionLogger $decisionLogger,
        protected MailNotifier $mailNotifier,
    ) {}

    /**
     * @param  CandleProviderInterface  $candleFetcher  REST or streaming implementation
     * @param  bool  $canOpenNew  false = portfolio cap full hai (sirf exits/trailing manage honge)
     */
    public function process(
        string $symbol,
        CandleProviderInterface $candleFetcher,
        bool $paperMode,
        ?PaperTrader $paperTrader,
        float $usdtBalance,
        bool $canOpenNew = true,
    ): void {
        // Load position state
        $state = $this->stateStore->loadPosition($symbol);
        $inPosition = $state['in_position'];

        // Portfolio cap poori: flat coins ka pipeline chalana hi bekaar —
        // koi naya entry nahi khul sakta. Open positions ke exits/trailing
        // neeche normal process hote rahenge.
        if (! $inPosition && ! $canOpenNew) {
            return;
        }

        // Fetch & validate candles
        $result = $candleFetcher->fetch($symbol, Config::get('bot.market.timeframe'));
        $series = $result->series;
        $qualityReport = $result->report;

        if (! $qualityReport->isValid) {
            Log::warning("[{$symbol}] Data quality invalid: {$qualityReport->summary()}");

            return;
        }

        // --- HARD SL/TP BREACH CHECK (always-on risk management) ---
        // Indicator kill-switch se INDEPENDENT — SL/TP cross pe foran exit.
        if ($inPosition) {
            $close = (float) ($series->last()?->close ?? 0);
            $sl = $state['stop_loss'] ?? null;
            $tp = $state['take_profit'] ?? null;

            $breachReason = null;
            if ($close > 0 && $sl !== null && $sl > 0 && $close <= $sl) {
                // %g = compact (trailing zeros hatte) — close_reason varchar(50) safe
                $breachReason = sprintf('stop_loss_hit: %.6g <= SL %.6g', $close, $sl);
            } elseif ($close > 0 && $tp !== null && $tp > 0 && $close >= $tp) {
                $breachReason = sprintf('take_profit_hit: %.6g >= TP %.6g', $close, $tp);
            }

            if ($breachReason !== null) {
                $breachDecision = new Decision(
                    action: Decision::EXIT,
                    reason: $breachReason,
                );
                $this->decisionLogger->logDecision($breachDecision, $symbol, $close, $usdtBalance);
                $this->executeExit($symbol, $breachDecision, $series, $paperMode, $paperTrader);

                return;
            }
        }

        // HTF candles for scoring
        $htfResult = $candleFetcher->fetch($symbol, Config::get('bot.market.higher_timeframe'));
        $htfFeatures = null;
        if ($htfResult->report->isValid) {
            $htfFeatures = Indicators::computeAll($htfResult->series, Config::get('bot.indicators'));
        }

        // Decision pipeline
        $reliabilityTracker = ReliabilityTracker::forDirection('BUY');
        $decision = $this->decisionEngine->makeDecision(
            series: $series,
            qualityReport: $qualityReport,
            usdtBalance: $usdtBalance,
            inPosition: $inPosition,
            circuitBreaker: $this->circuitBreaker,
            htfFeatures: $htfFeatures,
            reliabilityTracker: $reliabilityTracker,
        );

        // Log decision (audit + friendly)
        $currentPrice = $series->last()?->close ?? 0;
        $this->decisionLogger->logDecision($decision, $symbol, $currentPrice, $usdtBalance);

        // Execute decision
        if ($decision->action === Decision::BUY && ! $inPosition) {
            $this->executeBuy($symbol, $decision, $series, $paperMode, $paperTrader, $usdtBalance);
        } elseif ($decision->action === Decision::EXIT && $inPosition) {
            $this->executeExit($symbol, $decision, $series, $paperMode, $paperTrader);
        } elseif ($decision->action === Decision::HOLD) {
            // Check trailing stop if in position
            if ($inPosition) {
                $this->updateTrailingStop($symbol, $series);
            }
        }
    }

    protected function executeBuy(
        string $symbol,
        Decision $decision,
        CandleSeries $series,
        bool $paperMode,
        ?PaperTrader $paperTrader,
        float $usdtBalance,
    ): void {
        $currentPrice = $series->last()?->close ?? 0;
        $features = Indicators::computeAll($series, Config::get('bot.indicators'));
        $atr = $features->last('atr') ?? 0;

        $riskAssessment = $this->riskAssessor->assess($currentPrice, $atr, $usdtBalance);

        if (! $riskAssessment->approved) {
            Log::info("[{$symbol}] Risk rejected: {$riskAssessment->rejectionReason}");

            return;
        }

        $qty = $riskAssessment->positionSizeQuantity;
        $sl = $riskAssessment->stopLossPrice;
        $tp = $riskAssessment->takeProfitPrice;

        $clientId = 'bot_'.str_replace('/', '_', $symbol).'_'.time();

        // Defense 1: pre-entry guard — agar exchange pe isi coin ka purana bag
        // para hai to BUY mat karo (repeat-buy/orphan loop prevention)
        if (! $paperMode) {
            try {
                $base = explode('/', $symbol)[0];
                $heldQty = $this->trader->getAssetBalances()[$base] ?? 0;
                if ($heldQty > 0 && ($heldQty * $currentPrice) > 1.0) {
                    Log::warning("[{$symbol}] Skipping BUY: {$heldQty} {$base} already held on exchange (~".round($heldQty * $currentPrice, 2).' USD). Run bot:reconcile.');

                    return;
                }
            } catch (\Throwable) {
                // best-effort guard; balance fetch failure shouldn't block trading
            }
        }

        try {
            if ($paperMode && $paperTrader) {
                $ok = $paperTrader->buy($symbol, $currentPrice, $qty, $sl, $tp);
                if (! $ok) {
                    throw new \RuntimeException('Paper buy failed: insufficient balance');
                }
            } else {
                $this->trader->placeMarketBuy($symbol, $qty, $clientId);
                usleep(500_000);

                // Fill-price refresh must NEVER throw after a live fill —
                // fallback to signal price keeps state saving on-track.
                try {
                    $currentPrice = $this->trader->getCurrentPrice($symbol);
                } catch (\Throwable) {
                    // keep signal price as entry estimate
                }
                $sl = $currentPrice - ($atr * Config::get('bot.risk.atr_stop_loss_multiplier', 2.0));
                $tp = $currentPrice + ($atr * Config::get('bot.risk.atr_take_profit_multiplier', 4.0));
            }

            // Persist state IMMEDIATELY after verified fill, before any other
            // risky work (notification failures can't orphan us now).
            $this->stateStore->savePosition($symbol, [
                'in_position' => true,
                'entry_price' => $currentPrice,
                'quantity' => $qty,
                'stop_loss' => $sl,
                'take_profit' => $tp,
                'direction' => 'BUY',
                'confidence' => $decision->confidence,
                'client_id' => $clientId,
            ]);

            try {
                $this->decisionLogger->printTradeOpened($symbol, 'BUY', $qty, $currentPrice, $sl, $tp, $decision->confidence);
                $this->mailNotifier->notifyTradeOpened($symbol, $qty, $currentPrice, $sl, $tp, $usdtBalance, $decision->confidence);
            } catch (\Throwable $e) {
                Log::warning("[Processor] post-fill notify failed for {$symbol}: {$e->getMessage()}");
            }

            Log::info("[{$symbol}] ✅ BUY executed: {$qty} @ {$currentPrice} SL={$sl} TP={$tp}");
        } catch (\Throwable $e) {
            $this->decisionLogger->printOrderFailed($symbol, 'BUY', $e->getMessage());
            Log::error("[{$symbol}] Buy failed: {$e->getMessage()}");
        }
    }

    protected function executeExit(
        string $symbol,
        Decision $decision,
        CandleSeries $series,
        bool $paperMode,
        ?PaperTrader $paperTrader,
    ): void {
        $state = $this->stateStore->loadPosition($symbol);
        $entryPrice = $state['entry_price'] ?? 0;
        $qty = $state['quantity'] ?? 0;
        $currentPrice = $series->last()?->close ?? 0;

        try {
            if ($paperMode && $paperTrader) {
                $paperTrader->sell($symbol, $currentPrice, $decision->reason);
            } else {
                // sellBasePosition fee-adjusts (actual held qty) + precision-rounds,
                // state qty se direct sell "Insufficient balance" deta hai.
                $result = $this->trader->sellBasePosition($symbol, $qty);
                if (($result['sold_qty'] ?? 0) <= 0) {
                    throw new \RuntimeException('Nothing to sell (zero balance)');
                }
                $this->trader->cancelOpenOrders($symbol);
            }

            $pnlPct = $entryPrice > 0 ? (($currentPrice - $entryPrice) / $entryPrice) * 100 : 0;

            // Position exchange par close ho chuki — ab sirf bookkeeping.
            // Audit write fail ho to bhi position-state cleared rehna ZARURI hai.
            $this->stateStore->savePosition($symbol, $this->defaultPosition());

            try {
                Trade::create([
                    'symbol' => $symbol,
                    'mode' => $paperMode ? 'PAPER' : 'LIVE',
                    'direction' => 'BUY',
                    'status' => 'CLOSED',
                    'entry_price' => $entryPrice,
                    'exit_price' => $currentPrice,
                    'quantity' => $qty,
                    'stop_loss' => $state['stop_loss'] ?? null,
                    'take_profit' => $state['take_profit'] ?? null,
                    'confidence' => $state['confidence'] ?? null,
                    'pnl_percent' => round($pnlPct, 4),
                    'pnl_usdt' => round($currentPrice * $qty - $entryPrice * $qty, 8),
                    'close_reason' => mb_substr($decision->reason, 0, 50),
                    'opened_at' => $state['entry_time'] ?? now(),
                    'closed_at' => now(),
                ]);

                $this->decisionLogger->printTradeClosed($symbol, $decision->reason, $currentPrice, $entryPrice, $pnlPct);
                $this->mailNotifier->notifyTradeClosed($symbol, $decision->reason, $entryPrice, $currentPrice, $pnlPct, 0);

                Log::info("[{$symbol}] 🔴 EXIT executed: {$qty} @ {$currentPrice} PnL=".number_format($pnlPct, 2).'%');
            } catch (\Throwable $e) {
                Log::error("[{$symbol}] Exit bookkeeping failed (position closed on exchange): {$e->getMessage()}");
            }
        } catch (\Throwable $e) {
            $this->decisionLogger->printOrderFailed($symbol, 'SELL', $e->getMessage());
            Log::error("[{$symbol}] Exit failed: {$e->getMessage()}");
        }
    }

    protected function updateTrailingStop(
        string $symbol,
        CandleSeries $series,
    ): void {
        $state = $this->stateStore->loadPosition($symbol);
        $sl = $state['stop_loss'] ?? 0;
        $entry = $state['entry_price'] ?? 0;

        $features = Indicators::computeAll($series, Config::get('bot.indicators'));
        $atr = $features->last('atr') ?? 0;

        if ($sl <= 0 || $entry <= 0 || $atr <= 0) {
            return;
        }

        // Breakeven lock must sit BELOW TP distance, else trailing never engages
        // (old bug: lock at 2.5% but TP ≈ 4×ATR ≈ 1-1.8% → dead code).
        $price = $series->last()?->close ?? 0;
        $tpDistPct = $price > 0
            ? (Config::get('bot.risk.atr_take_profit_multiplier', 4.0) * $atr / $price) * 100
            : 2.5;
        $lockPct = max(0.3, round($tpDistPct * 0.6, 2));

        $newSl = TrailingStop::update(
            entryPrice: $entry,
            currentPrice: $series->last()?->close ?? 0,
            originalStopLoss: $sl,
            atr: $atr,
            trailMultiplier: Config::get('bot.risk.atr_stop_loss_multiplier', 2.0) * 0.75,
            minProfitLockPct: $lockPct,
        );

        if ($newSl > $sl) {
            $state['stop_loss'] = $newSl;
            $this->stateStore->savePosition($symbol, $state);
            Log::info("[{$symbol}] 📈 Trailing SL updated: ".number_format($sl, 4).' → '.number_format($newSl, 4));

            if (! Config::get('bot.exchange.use_demo', false) && ! Config::get('bot.exchange.use_testnet', false)) {
                $this->trader->cancelOpenOrders($symbol);
                $this->trader->placeStopLoss($symbol, $state['quantity'] ?? 0, $newSl);
            }
        }
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
            'pending_entry' => null,
        ];
    }
}
