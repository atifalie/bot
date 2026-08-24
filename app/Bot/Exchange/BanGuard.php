<?php

namespace App\Bot\Exchange;

use ccxt\AuthenticationError;
use ccxt\BadSymbol;
use ccxt\DDoSProtection;
use ccxt\PermissionDenied;
use ccxt\RateLimitExceeded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BanGuard
{
    protected const BAN_KEY = 'bot.exchange.banned_until_ms';

    protected const WEIGHT_KEY = 'bot.exchange.used_weight_1m';

    public function __construct(
        protected int $weightLimitPerMinute = 6000,
        protected float $weightWarnFraction = 0.60,
        protected float $weightPauseFraction = 0.85,
        protected int $plainRateLimitCooldownMs = 60_000,
    ) {}

    public function bannedUntilMs(): int
    {
        return (int) Cache::get(self::BAN_KEY, 0);
    }

    public function isBanned(): bool
    {
        return microtime(true) * 1000 < $this->bannedUntilMs();
    }

    public function isRateLimitError(\Throwable $e): bool
    {
        if ($e instanceof RateLimitExceeded || $e instanceof DDoSProtection) {
            return true;
        }

        $msg = $e->getMessage();

        if (str_contains($msg, '-1003') || str_contains($msg, 'banned until')
            || str_contains(strtolower($msg), 'request weight')) {
            return true;
        }

        return (bool) preg_match("/\b418\s+I'?m a teapot|\b429\s+Too Many Requests/i", $msg);
    }

    public function isDeterministicError(\Throwable $e): bool
    {
        if ($e instanceof BadSymbol || $e instanceof AuthenticationError || $e instanceof PermissionDenied) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'does not have market symbol')
            || str_contains($msg, 'invalid symbol');
    }

    /**
     * Parses ban expiry from a rate-limit exception and marks it globally.
     * Escalating bans make any retry during a ban counterproductive, so every
     * fetch fails fast while the mark is active.
     */
    public function markBanFromException(\Throwable $e): int
    {
        if (preg_match('/banned until (\d+)/', $e->getMessage(), $m)) {
            $until = (int) $m[1];
            Cache::put(self::BAN_KEY, $until);
            Log::warning(sprintf(
                '[BanGuard] IP BAN until %d (~%.1f min) — all fetches paused',
                $until,
                max(0, ($until - microtime(true) * 1000)) / 60000,
            ));
        } else {
            $until = (int) (microtime(true) * 1000) + $this->plainRateLimitCooldownMs;
            Cache::put(self::BAN_KEY, $until);
            Log::warning(sprintf(
                '[BanGuard] 429 rate limit — %ds cooldown on all fetches',
                (int) ($this->plainRateLimitCooldownMs / 1000),
            ));
        }

        return $until;
    }

    /**
     * Tracks the X-MBX-USED-WEIGHT-1M response header and self-throttles
     * before the exchange ever needs to send a 429.
     */
    public function trackWeightHeader(?array $headers): void
    {
        if (! $headers) {
            return;
        }

        try {
            foreach ($headers as $key => $value) {
                if (strtolower(str_replace('-', '', (string) $key)) === 'xmbxusedweight1m') {
                    $used = (int) $value;
                    Cache::put(self::WEIGHT_KEY, $used, 60);
                    break;
                }
            }

            $used = (int) Cache::get(self::WEIGHT_KEY, 0);
            $pauseThreshold = (int) ($this->weightLimitPerMinute * $this->weightPauseFraction);
            $warnThreshold = (int) ($this->weightLimitPerMinute * $this->weightWarnFraction);

            if ($used >= $pauseThreshold && $pauseThreshold > 0) {
                Log::warning("[BanGuard] weight {$used}/{$this->weightLimitPerMinute} — 10s self-throttle");
                sleep(10);
            } elseif ($used >= $warnThreshold && $warnThreshold > 0) {
                Log::warning("[BanGuard] weight usage high: {$used}/{$this->weightLimitPerMinute}");
            }
        } catch (\Throwable) {
            // Header missing or unparseable — never block trading over this.
        }
    }
}
