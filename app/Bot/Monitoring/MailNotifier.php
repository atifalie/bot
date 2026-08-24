<?php

namespace App\Bot\Monitoring;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mail Notifier — trade open/close email notifications.
 * Port of monitoring/notify.py — uses Laravel Mail instead of raw smtplib.
 */
class MailNotifier
{
    public function __construct() {}

    public function notifyTradeOpened(
        string $symbol,
        float $quantity,
        float $entryPrice,
        float $stopLoss,
        float $takeProfit,
        float $balance,
        ?float $confidence = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $confStr = $confidence !== null ? "\nConfidence: ".number_format($confidence, 0).'/100' : '';
        $subject = "🟢 TRADE OPENED — {$symbol}";
        $body = "TRADE OPENED\n\n"
            ."Symbol: {$symbol}\n"
            ."Action: BUY\n"
            .'Quantity: '.number_format($quantity, 6)."\n"
            .'Entry Price: '.number_format($entryPrice, 2)."\n"
            .'Stop Loss: '.number_format($stopLoss, 2)."\n"
            .'Take Profit: '.number_format($takeProfit, 2)."{$confStr}\n\n"
            .'Balance: '.number_format($balance, 2)." USDT\n\n"
            ."---\n"
            ."Trading Bot (Spot Scalping)\n";

        $this->send($subject, $body);
    }

    public function notifyTradeClosed(
        string $symbol,
        string $reason,
        float $entryPrice,
        float $exitPrice,
        float $pnlPercent,
        float $balance,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $icon = $pnlPercent > 0 ? '🟢 PROFIT' : '🔴 LOSS';
        $subject = "{$icon} TRADE CLOSED — {$symbol} (".($pnlPercent >= 0 ? '+' : '').number_format($pnlPercent, 2).'%)';
        $body = "TRADE CLOSED ({$reason})\n\n"
            ."Symbol: {$symbol}\n"
            .'Entry Price: '.number_format($entryPrice, 2)."\n"
            .'Exit Price: '.number_format($exitPrice, 2)."\n"
            .'PnL: '.($pnlPercent >= 0 ? '+' : '').number_format($pnlPercent, 2)."%\n\n"
            .'Balance: '.number_format($balance, 2)." USDT\n\n"
            ."---\n"
            ."Trading Bot (Spot Scalping)\n";

        $this->send($subject, $body);
    }

    protected function enabled(): bool
    {
        return Config::get('bot.notify.email_enabled', false);
    }

    protected function send(string $subject, string $body): void
    {
        if (! $this->enabled()) {
            return;
        }

        $to = Config::get('bot.notify.email_to') ?: Config::get('bot.notify.email_address');

        if (! $to) {
            Log::warning('[MailNotifier] No recipient email configured');

            return;
        }

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
            Log::info("[MailNotifier] Email sent: {$subject}");
        } catch (\Throwable $e) {
            Log::error("[MailNotifier] Email failed: {$e->getMessage()}");
        }
    }
}
