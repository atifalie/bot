<?php

namespace App\Bot\Config;

use InvalidArgumentException;

class BotSettings
{
    public static function get(string $path, mixed $default = null): mixed
    {
        return config("bot.{$path}", $default);
    }

    public static function profile(): string
    {
        return (string) config('bot.profile');
    }

    public static function validate(): void
    {
        $weights = (array) config('bot.weights');
        $sum = array_sum($weights);

        if (! ($sum >= 0.99 && $sum <= 1.01)) {
            throw new InvalidArgumentException(
                "Scoring weights sum must be ~1.0, got {$sum}"
            );
        }

        $riskPct = (float) config('bot.risk.risk_per_trade_percent');

        if ($riskPct <= 0 || $riskPct > 10) {
            throw new InvalidArgumentException(
                "risk_per_trade_percent must be within (0, 10] for safety, got {$riskPct}"
            );
        }
    }
}
