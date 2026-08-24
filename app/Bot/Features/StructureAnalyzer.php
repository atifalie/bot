<?php

namespace App\Bot\Features;

use App\Bot\MarketData\Candle;
use App\Bot\MarketData\CandleSeries;

/**
 * S/R zone detection from swing-pivot fractals plus pullback/rejection
 * scoring — answers "is this a good LOCATION to enter?", which raw
 * indicator crossovers cannot.
 */
class StructureAnalyzer
{
    public function __construct(
        protected CandleSeries $series,
        protected float $atrValue = 0.0,
    ) {}

    /** @return array{highs: list<int>, lows: list<int>} pivot indices */
    public function detectPivots(?CandleSeries $series = null, int $left = 3, int $right = 3): array
    {
        $series ??= $this->series;
        $highs = $series->highs();
        $lows = $series->lows();
        $n = count($highs);

        $pivotHighs = [];
        $pivotLows = [];

        for ($i = $left; $i < $n - $right; $i++) {
            $windowHigh = array_slice($highs, $i - $left, $left + $right + 1);
            $windowLow = array_slice($lows, $i - $left, $left + $right + 1);

            if ($highs[$i] >= max($windowHigh)
                && count(array_filter($windowHigh, fn ($v) => $v > $highs[$i])) === 0) {
                $pivotHighs[] = $i;
            }

            if ($lows[$i] <= min($windowLow)
                && count(array_filter($windowLow, fn ($v) => $v < $lows[$i])) === 0) {
                $pivotLows[] = $i;
            }
        }

        return ['highs' => $pivotHighs, 'lows' => $pivotLows];
    }

    /**
     * Clusters pivot levels into zones within tol = tol_frac × ATR of each
     * other; more touches means a stronger level.
     *
     * @return list<PriceZone>
     */
    public function buildZones(int $lookback = 120, float $tolFracOfAtr = 0.75, int $maxZones = 14): array
    {
        $candles = $this->series->candles();

        if (count($candles) > $lookback) {
            $candles = array_slice($candles, -$lookback);
        }

        $recent = new CandleSeries($candles);

        if ($recent->count() < 25) {
            return [];
        }

        $atrVal = $this->atrValue;

        if (! is_finite($atrVal) || $atrVal <= 0) {
            $ranges = array_map(fn (Candle $c) => $c->high - $c->low, $recent->candles());
            sort($ranges);
            $atrVal = self::median($ranges);
        }

        if ($atrVal <= 0) {
            return [];
        }

        $pivots = $this->detectPivots($recent);
        $recentHighs = $recent->highs();
        $recentLows = $recent->lows();

        $levels = [
            ...array_map(fn (int $i) => $recentHighs[$i], $pivots['highs']),
            ...array_map(fn (int $i) => $recentLows[$i], $pivots['lows']),
        ];
        sort($levels);

        if ($levels === []) {
            return [];
        }

        $tol = $atrVal * $tolFracOfAtr;

        $zones = [];
        $cluster = [$levels[0]];

        for ($j = 1, $m = count($levels); $j < $m; $j++) {
            if ($tol >= $levels[$j] - end($cluster)) {
                $cluster[] = $levels[$j];

                continue;
            }

            $zones[] = self::zoneFromCluster($cluster);
            $cluster = [$levels[$j]];
        }
        $zones[] = self::zoneFromCluster($cluster);

        usort($zones, fn (PriceZone $a, PriceZone $b) => $b->touches <=> $a->touches);

        return array_slice($zones, 0, $maxZones);
    }

    /** Nearest support zone below the price (slight overlap tolerated). */
    public static function nearestSupportBelow(float $price, array $zones): ?PriceZone
    {
        $best = null;
        $bestDist = null;

        foreach ($zones as $zone) {
            if ($zone->hi > $price * 1.002) {
                continue;
            }

            $dist = $price - $zone->hi;

            if ($bestDist === null || $dist < $bestDist) {
                $best = $zone;
                $bestDist = $dist;
            }
        }

        return $best;
    }

    /**
     * Bullish rejection candle: long lower wick (demand defended) with close
     * in the upper half of the range.
     */
    public static function bullishRejection(Candle $candle): bool
    {
        $range = $candle->high - $candle->low;

        if ($range <= 0) {
            return false;
        }

        $lowerWick = min($candle->open, $candle->close) - $candle->low;

        return $lowerWick >= 0.38 * $range
            && ($candle->close >= $candle->open || ($candle->close - $candle->low) >= 0.6 * $range);
    }

    /**
     * STRUCTURE SCORE (0-100):
     *   proximity to support (pullback quality) base 15-70,
     *   bullish rejection at the zone +25,
     *   zone strength via touch count up to +15.
     *
     * @return array{score: float, note: string}
     */
    public function structureFactor(): array
    {
        $candle = $this->series->last();

        if ($candle === null) {
            return ['score' => 50.0, 'note' => 'no_structure_data'];
        }

        $price = $candle->close;
        $zones = $this->buildZones();

        if ($zones === []) {
            return ['score' => 50.0, 'note' => 'no_structure_data'];
        }

        $support = self::nearestSupportBelow($price, $zones);

        if ($support === null) {
            return ['score' => 20.0, 'note' => 'above_all_supports'];
        }

        $atrVal = is_finite($this->atrValue) && $this->atrValue > 0
            ? $this->atrValue
            : max($price * 0.001, 1e-12);

        $d = ($price - $support->hi) / $atrVal;

        $base = match (true) {
            $d <= 0.5 => 70.0,
            $d <= 1.0 => 60.0,
            $d <= 1.5 => 50.0,
            $d <= 2.5 => 38.0,
            $d <= 4.0 => 26.0,
            default => 14.0,
        };

        $score = $base;
        $notes = [sprintf('sup@%g(%.1fatr,%dx)', $support->mid, $d, $support->touches)];

        if ($d <= 1.2 && self::bullishRejection($candle)) {
            $score += 25.0;
            $notes[] = 'rejection';
        }

        $score += min(15.0, max(0.0, ($support->touches - 1) * 7.0));

        return ['score' => min(100.0, $score), 'note' => implode(' ', $notes)];
    }

    /** @param list<float> $cluster */
    protected static function zoneFromCluster(array $cluster): PriceZone
    {
        return new PriceZone(
            lo: min($cluster),
            hi: max($cluster),
            mid: array_sum($cluster) / count($cluster),
            touches: count($cluster),
        );
    }

    /** @param list<float> $values */
    protected static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
