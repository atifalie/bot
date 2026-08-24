<?php

namespace App\Bot\Strategy;

/**
 * Session filter — port of strategy/session.py
 * Determines current trading session quality (UTC).
 */
class SessionFilter
{
    public static function getCurrentSession(?\DateTimeInterface $utcNow = null): array
    {
        $utcNow ??= new \DateTime('now', new \DateTimeZone('UTC'));
        $hour = (int) $utcNow->format('H');

        if ($hour >= 13 && $hour < 17) {
            return ['session' => 'overlap', 'quality' => 'best', 'tradeable' => true,
                'reason' => 'london_us_overlap_high_volume'];
        }
        if ($hour >= 7 && $hour < 13) {
            return ['session' => 'london', 'quality' => 'high', 'tradeable' => true,
                'reason' => 'london_session_active'];
        }
        if ($hour >= 17 && $hour < 23) {
            return ['session' => 'evening', 'quality' => 'medium', 'tradeable' => true,
                'reason' => 'us_afternoon_moderate_volume'];
        }
        if ($hour >= 23 || $hour < 3) {
            return ['session' => 'asian', 'quality' => 'low', 'tradeable' => false,
                'reason' => 'asian_session_low_liquidity'];
        }

        return ['session' => 'dead_zone', 'quality' => 'worst', 'tradeable' => false,
            'reason' => 'dead_zone_no_major_market_open'];
    }

    public static function isHighQualitySession(?\DateTimeInterface $utcNow = null): bool
    {
        $session = self::getCurrentSession($utcNow);

        return in_array($session['quality'], ['best', 'high'], true);
    }

    public static function isTradeable(?\DateTimeInterface $utcNow = null, bool $requireHighQuality = false): bool
    {
        $session = self::getCurrentSession($utcNow);
        if ($requireHighQuality) {
            return $session['quality'] === 'best' || $session['quality'] === 'high';
        }

        return $session['tradeable'];
    }
}
