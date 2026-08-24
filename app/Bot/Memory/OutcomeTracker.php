<?php

namespace App\Bot\Memory;

use App\Bot\Exchange\Trader;
use App\Models\Signal;
use App\Models\SignalOutcome;
use Illuminate\Support\Facades\Log;

/**
 * Outcome Tracker — monitors post-signal price action.
 * Port of memory/outcome_tracker.py — MySQL instead of SQLite, uses Trader for OHLCV.
 */
class OutcomeTracker
{
    protected const TIMEFRAMES = [
        '15m' => 15,
        '30m' => 30,
        '1h' => 60,
        '4h' => 240,
        '24h' => 1440,
    ];

    protected const FETCH_DELAY_SECONDS = 0.3;

    protected const BATCH_SIZE = 20;

    protected const MAX_SIGNAL_AGE_HOURS = 24;

    public function __construct(protected Trader $trader) {}

    public function trackPendingSignals(): void
    {
        $cutoff = now()->subHours(self::MAX_SIGNAL_AGE_HOURS);

        $pending = Signal::query()
            ->where('outcome_tracked', false)
            ->where('signaled_at', '>', $cutoff)
            ->orderBy('signaled_at', 'asc')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        Log::info("[OutcomeTracker] Tracking outcomes for {$pending->count()} signals...");

        $trackedCount = 0;

        // Group by symbol to batch OHLCV fetches
        $bySymbol = $pending->groupBy('symbol');

        foreach ($bySymbol as $symbol => $signals) {
            try {
                $ohlcv = $this->trader->fetchOhlcv($symbol, '1m', 1440);
                usleep((int) (self::FETCH_DELAY_SECONDS * 1_000_000));
            } catch (\Throwable $e) {
                Log::warning("[OutcomeTracker] OHLCV fetch failed for {$symbol}: {$e->getMessage()}");

                continue;
            }

            if ($ohlcv === [] || count($ohlcv) < 10) {
                continue;
            }

            foreach ($signals as $signal) {
                $outcome = $this->calculateOutcomeFromData($signal, $ohlcv);

                if ($outcome === null) {
                    continue;
                }

                SignalOutcome::create([
                    'signal_id' => $signal->id,
                    'symbol' => $symbol,
                    'price_at_signal' => $outcome['price_at_signal'],
                    'max_gain_15m' => $outcome['max_gain_15m'] ?? null,
                    'max_drawdown_15m' => $outcome['max_drawdown_15m'] ?? null,
                    'max_gain_30m' => $outcome['max_gain_30m'] ?? null,
                    'max_drawdown_30m' => $outcome['max_drawdown_30m'] ?? null,
                    'max_gain_1h' => $outcome['max_gain_1h'] ?? null,
                    'max_drawdown_1h' => $outcome['max_drawdown_1h'] ?? null,
                    'max_gain_4h' => $outcome['max_gain_4h'] ?? null,
                    'max_drawdown_4h' => $outcome['max_drawdown_4h'] ?? null,
                    'max_gain_24h' => $outcome['max_gain_24h'] ?? null,
                    'max_drawdown_24h' => $outcome['max_drawdown_24h'] ?? null,
                    'reached_5pct' => $outcome['reached_5pct'] ?? false,
                    'reached_10pct' => $outcome['reached_10pct'] ?? false,
                    'reached_minus_5pct' => $outcome['reached_minus_5pct'] ?? false,
                    'tracked_at' => now(),
                ]);

                $signal->update(['outcome_tracked' => true]);
                $trackedCount++;
            }
        }

        Log::info("[OutcomeTracker] Tracked {$trackedCount} signal outcomes");
    }

    protected function calculateOutcomeFromData(Signal $signal, array $ohlcv): ?array
    {
        $signalTime = $signal->signaled_at->getTimestamp() * 1000;
        $postSignal = array_filter($ohlcv, fn ($c) => $c[0] >= $signalTime);

        if (count($postSignal) < 5) {
            return null;
        }

        $priceAtSignal = $postSignal[0][1]; // open of first post-signal candle
        $result = ['price_at_signal' => $priceAtSignal];

        foreach (self::TIMEFRAMES as $tfName => $tfMinutes) {
            $window = array_slice($postSignal, 0, $tfMinutes);
            if ($window === []) {
                continue;
            }

            $highs = array_column($window, 2);
            $lows = array_column($window, 3);
            $maxHigh = max($highs);
            $maxLow = min($lows);

            $gainPct = (($maxHigh - $priceAtSignal) / $priceAtSignal) * 100;
            $drawdownPct = (($priceAtSignal - $maxLow) / $priceAtSignal) * 100;

            $result["max_gain_{$tfName}"] = round($gainPct, 2);
            $result["max_drawdown_{$tfName}"] = round($drawdownPct, 2);
        }

        $maxGain = max(array_filter($result, fn ($k, $v) => str_starts_with($k, 'max_gain_'), ARRAY_FILTER_USE_KEY) ?: [0]);
        $maxDd = max(array_filter($result, fn ($k, $v) => str_starts_with($k, 'max_drawdown_'), ARRAY_FILTER_USE_KEY) ?: [0]);

        $result['reached_5pct'] = $maxGain >= 5.0;
        $result['reached_10pct'] = $maxGain >= 10.0;
        $result['reached_minus_5pct'] = $maxDd >= 5.0;

        return $result;
    }

    public static function getOutcomeStats(?string $symbol = null): array
    {
        $query = SignalOutcome::query();
        if ($symbol) {
            $query->where('symbol', $symbol);
        }

        $total = $query->count();
        if ($total === 0) {
            return [
                'total_tracked' => 0,
                'reached_5pct' => 0, 'reached_5pct_rate' => 0,
                'reached_10pct' => 0, 'reached_10pct_rate' => 0,
                'hit_minus_5pct' => 0, 'hit_minus_5pct_rate' => 0,
                'avg_gain_1h' => 0, 'avg_drawdown_1h' => 0,
            ];
        }

        $reached5 = (clone $query)->where('reached_5pct', true)->count();
        $reached10 = (clone $query)->where('reached_10pct', true)->count();
        $hitMinus5 = (clone $query)->where('reached_minus_5pct', true)->count();

        $avgGain1h = (clone $query)->whereNotNull('max_gain_1h')->avg('max_gain_1h') ?? 0;
        $avgDd1h = (clone $query)->whereNotNull('max_drawdown_1h')->avg('max_drawdown_1h') ?? 0;

        return [
            'total_tracked' => $total,
            'reached_5pct' => $reached5,
            'reached_5pct_rate' => round(($reached5 / $total) * 100, 2),
            'reached_10pct' => $reached10,
            'reached_10pct_rate' => round(($reached10 / $total) * 100, 2),
            'hit_minus_5pct' => $hitMinus5,
            'hit_minus_5pct_rate' => round(($hitMinus5 / $total) * 100, 2),
            'avg_gain_1h' => round($avgGain1h, 2),
            'avg_drawdown_1h' => round($avgDd1h, 2),
        ];
    }
}
