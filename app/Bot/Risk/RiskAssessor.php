<?php

namespace App\Bot\Risk;

use Illuminate\Support\Facades\Config;

/**
 * Risk Assessor — main risk assessment (SPOT-ONLY BUY).
 * ATR-based SL/TP, fixed-dollar or % risk sizing, R:R validation,
 * circuit breaker + drawdown checks.
 * Port of risk/risk_manager.py assess_trade().
 */
class RiskAssessor
{
    public function __construct(
        protected CircuitBreaker $circuitBreaker,
    ) {}

    public function assess(
        float $currentPrice,
        float $atr,
        float $usdtBalance,
        ?array $riskConfigOverride = null,
    ): RiskAssessment {
        $riskConfig = $riskConfigOverride ?? Config::get('bot.risk');

        // Max drawdown check
        [$ddOk, $ddReason] = $this->circuitBreaker->isDrawdownOk($usdtBalance);
        if (! $ddOk) {
            return new RiskAssessment(false, 0, 0, 0, 0, 0, $ddReason);
        }

        // Circuit breaker trading allowed
        [$allowed, $reason] = $this->circuitBreaker->isTradingAllowed();
        if (! $allowed) {
            return new RiskAssessment(false, 0, 0, 0, 0, 0, $reason);
        }

        if (! is_finite($atr) || $atr <= 0) {
            return new RiskAssessment(false, 0, 0, 0, 0, 0, 'invalid_atr');
        }

        // SPOT BUY: stop-loss below entry, take-profit above entry
        $slMult = $riskConfig['atr_stop_loss_multiplier'] ?? 2.0;
        $tpMult = $riskConfig['atr_take_profit_multiplier'] ?? 4.0;

        $stopLossPrice = $currentPrice - ($atr * $slMult);
        $takeProfitPrice = $currentPrice + ($atr * $tpMult);
        $riskPerUnit = $currentPrice - $stopLossPrice;
        $rewardPerUnit = $takeProfitPrice - $currentPrice;

        if ($riskPerUnit <= 0) {
            return new RiskAssessment(false, 0, 0, 0, 0, 0, 'non_positive_risk_per_unit');
        }

        $riskRewardRatio = $rewardPerUnit / $riskPerUnit;
        $minRr = $riskConfig['min_risk_reward_ratio'] ?? 1.5;

        if ($riskRewardRatio < $minRr) {
            return new RiskAssessment(
                false, 0, $stopLossPrice, $takeProfitPrice, $riskRewardRatio, 0,
                sprintf('rr_too_low(%.2f<%.2f)', $riskRewardRatio, $minRr),
            );
        }

        if (! is_finite($usdtBalance) || $usdtBalance <= 0) {
            return new RiskAssessment(false, 0, 0, 0, 0, 0, 'zero_balance');
        }

        $slDistance = $atr * $slMult;  // $ loss per unit at SL
        $riskAmount = $usdtBalance * ($riskConfig['max_risk_per_trade_percent'] ?? 1.5) / 100.0;
        $maxPositionValue = $usdtBalance * ($riskConfig['max_position_percent_of_balance'] ?? 25) / 100.0;

        // FIXED DOLLAR MODE (TRADE_SIZE_USDT, default 10)
        $fixedSize = (float) ($riskConfig['fixed_trade_size_usdt'] ?? 10.0);
        if ($fixedSize > 0) {
            $targetValue = min($fixedSize, $maxPositionValue);
            $quantity = floor(($targetValue / $currentPrice) * 1e8) / 1e8;
        } else {
            $qtyByRisk = $riskAmount / $slDistance;
            $qtyByCap = $maxPositionValue / $currentPrice;
            $quantity = min($qtyByRisk, $qtyByCap);
            $quantity = floor($quantity * 1e8) / 1e8;
        }

        if (! is_finite($quantity) || $quantity <= 0) {
            return new RiskAssessment(false, 0, 0, 0, 0, 0, 'zero_or_negative_quantity');
        }

        $notional = $quantity * $currentPrice;
        $minNotional = $riskConfig['min_notional_usdt'] ?? 5.0;
        if ($notional < $minNotional) {
            return new RiskAssessment(
                false, 0, $stopLossPrice, $takeProfitPrice, $riskRewardRatio, 0,
                sprintf('notional_too_small(%.2f<%.2f)', $notional, $minNotional),
            );
        }

        $actualRiskUsdt = $quantity * $slDistance;

        return new RiskAssessment(
            approved: true,
            positionSizeQuantity: $quantity,
            stopLossPrice: $stopLossPrice,
            takeProfitPrice: $takeProfitPrice,
            riskRewardRatio: $riskRewardRatio,
            riskAmountUsdt: $actualRiskUsdt,
            rejectionReason: null,
        );
    }
}
