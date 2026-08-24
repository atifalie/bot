<?php

namespace App\Bot\Monitoring;

use App\Bot\Scoring\FactorScore;
use App\Bot\Strategy\Decision;
use Illuminate\Support\Facades\Log;

/**
 * Decision Logger — audit trail + friendly console output + execution confirmations.
 * Port of monitoring/explain.py — Laravel Log + custom channel.
 */
class DecisionLogger
{
    protected const FACTOR_PLAIN_NAMES = [
        'trend' => 'Trend (price direction)',
        'momentum' => 'Momentum (RSI)',
        'volume' => 'Trading volume',
        'volatility' => 'Price movement (volatility)',
        'macd' => 'MACD (secondary trend check)',
        'bollinger' => 'Bollinger Bands (mean reversion)',
        'vwap' => 'VWAP (volume-weighted avg price)',
        'stoch_rsi' => 'Stochastic RSI (fast momentum)',
        'regime' => 'Market regime (trending vs ranging)',
        'reliability' => 'Past performance of this setup',
        'htf_trend' => 'Bigger-picture trend (higher timeframe)',
        'structure' => 'Price structure (S/R proximity)',
    ];

    public function logDecision(
        Decision $decision,
        ?string $symbol = null,
        ?float $price = null,
        ?float $balance = null,
    ): void {
        // Full technical detail — file audit trail
        $payload = [
            'symbol' => $symbol,
            'action' => $decision->action,
            'confidence' => $decision->confidence,
            'band' => $decision->band,
            'reason' => $decision->reason,
        ];

        if ($decision->scoringResult !== null) {
            $sr = $decision->scoringResult;
            $payload['scoring'] = [
                'raw_weighted_score' => $sr->rawWeightedScore,
                'final_confidence' => $sr->finalConfidence,
                'band' => $sr->band,
                'direction' => $sr->direction,
                'critical_blockers' => $sr->criticalBlockers,
            ];
        }

        if ($decision->riskAssessment !== null) {
            $ra = $decision->riskAssessment;
            $payload['risk'] = [
                'approved' => $ra->approved,
                'position_size' => $ra->positionSizeQuantity,
                'stop_loss' => $ra->stopLossPrice,
                'take_profit' => $ra->takeProfitPrice,
                'risk_reward_ratio' => $ra->riskRewardRatio,
                'risk_amount_usdt' => $ra->riskAmountUsdt,
            ];
        }

        Log::channel('decisions')->info(json_encode($payload));
        Log::channel('decisions')->info($decision->fullExplanation());

        // Friendly console output for BUY/EXIT only
        if (! in_array($decision->action, [Decision::BUY, Decision::EXIT], true)) {
            return;
        }

        $this->printFriendly($decision, $symbol, $price, $balance);
    }

    public function printTradeOpened(
        string $symbol,
        string $direction,
        float $quantity,
        float $entryPrice,
        float $stopLoss,
        float $takeProfit,
        ?float $confidence = null,
    ): void {
        $confStr = $confidence !== null ? ' (confidence '.number_format($confidence, 0).')' : '';
        $msg = "\n✅ TRADE OPENED [{$symbol}]{$confStr}\n"
            ."   {$direction} ".number_format($quantity, 6).' @ '.number_format($entryPrice, 2)."\n"
            .'   Stop-loss: '.number_format($stopLoss, 2).'   Take-profit: '.number_format($takeProfit, 2)."\n";

        $this->notify($msg, 'green');
        Log::channel('decisions')->info("TRADE_OPENED symbol={$symbol} direction={$direction} qty=".number_format($quantity, 6).' entry='.number_format($entryPrice, 2).' sl='.number_format($stopLoss, 2).' tp='.number_format($takeProfit, 2));
    }

    public function printTradeClosed(
        string $symbol,
        string $reason,
        float $exitPrice,
        float $entryPrice,
        float $pnlPercent,
    ): void {
        $icon = $pnlPercent > 0 ? '🟢' : '🔴';
        $msg = "\n{$icon} TRADE CLOSED [{$symbol}] ({$reason})\n"
            .'   Entry: '.number_format($entryPrice, 2).'   Exit: '.number_format($exitPrice, 2).'   PnL: '.($pnlPercent >= 0 ? '+' : '').number_format($pnlPercent, 2)."%\n";

        $style = $pnlPercent > 0 ? 'green' : 'red';
        $this->notify($msg, $style);
        Log::channel('decisions')->info("TRADE_CLOSED symbol={$symbol} reason={$reason} entry=".number_format($entryPrice, 2).' exit='.number_format($exitPrice, 2).' pnl_pct='.number_format($pnlPercent, 2));
    }

    public function printOrderFailed(string $symbol, string $action, string $error): void
    {
        $msg = "\n❌ ORDER FAILED [{$symbol}] — {$action} did not execute: {$error}\n";
        $this->notify($msg, 'red');
        Log::channel('decisions')->error("ORDER_FAILED symbol={$symbol} action={$action} error={$error}");
    }

    protected function printFriendly(
        Decision $decision,
        ?string $symbol = null,
        ?float $price = null,
        ?float $balance = null,
    ): void {
        $action = $decision->action;
        $confidence = $decision->confidence;
        $band = $decision->band;

        $header = ($action === Decision::BUY ? '🟢 BUY' : '🔴 EXIT');
        if ($symbol) {
            $header .= "  [{$symbol}]";
        }

        $lines = ["\n{$header}"];

        if ($price !== null) {
            $lines[] = '   Price: '.number_format($price, 2);
        }

        if ($confidence !== null) {
            $lines[] = '   Confidence: '.number_format($confidence, 0)."/100 ({$band})";
        }

        if ($decision->scoringResult !== null && $decision->scoringResult->factors !== []) {
            $factors = $decision->scoringResult->factors;
            usort($factors, fn ($a, $b) => $a->score <=> $b->score);
            $weakest = array_slice($factors, 0, 2);
            $notes = array_map([$this, 'plainFactorNote'], $weakest);
            $lines[] = '   Main reason: '.implode(' | ', $notes);
        }

        if ($action === Decision::BUY && $decision->riskAssessment !== null) {
            $ra = $decision->riskAssessment;
            $lines[] = '   Stop-loss: '.number_format($ra->stopLossPrice, 2).'   Take-profit: '.number_format($ra->takeProfitPrice, 2);
        }

        $lines[] = '';
        $style = $action === Decision::BUY ? 'green' : 'yellow';
        $this->notify(implode("\n", $lines), $style);
    }

    protected function plainFactorNote(FactorScore $factor): string
    {
        $name = self::FACTOR_PLAIN_NAMES[$factor->name] ?? $factor->name;
        $verdict = match (true) {
            $factor->score >= 70 => 'good',
            $factor->score >= 45 => 'okay',
            default => 'weak',
        };
        $blockerTag = $factor->isCriticalBlocker ? ' (major concern)' : '';

        return "{$name}: {$verdict}{$blockerTag}";
    }

    protected function notify(string $message, string $style = 'default'): void
    {
        // In Laravel, we can use the console output or a custom notification channel
        // For now, write to a dedicated log channel that outputs to console
        Log::channel('console')->info($message);
    }
}
