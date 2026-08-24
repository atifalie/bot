<?php

namespace App\Bot\Memory;

use App\Models\Signal;
use Illuminate\Support\Facades\Log;

/**
 * Signal Logger — saves scanner signals to MySQL (signals table).
 * Port of memory/signal_logger.py — replaces SQLite with Eloquent.
 */
class SignalLogger
{
    public function logSignal(
        string $symbol,
        float $totalScore,
        string $tier,
        ?array $discovery = null,
        ?array $confirmation = null,
        ?array $ranking = null,
        ?array $btcData = null,
        string $session = 'unknown',
        string $timeframe = '5m',
    ): ?int {
        $now = now()->toDateTimeString();

        $signal = Signal::create([
            'signaled_at' => $now,
            'symbol' => $symbol,
            'timeframe' => $timeframe,

            // Discovery stage
            'discovery_score' => $discovery['score'] ?? null,
            'volume_acceleration' => $discovery['volume_acceleration'] ?? null,
            'price_acceleration' => $discovery['price_acceleration'] ?? null,
            'relative_volume' => $discovery['relative_volume'] ?? null,
            'trade_count' => $discovery['trade_count'] ?? null,
            'quote_volume' => $discovery['quote_volume'] ?? null,
            'price_change_24h' => $discovery['price_change_24h'] ?? null,
            'bid_ask_spread_pct' => $discovery['bid_ask_spread_pct'] ?? null,

            // Confirmation stage
            'confirmation_score' => $confirmation['score'] ?? null,
            'btc_trend' => $btcData['trend'] ?? 'NEUTRAL',
            'momentum_score' => $confirmation['momentum_score'] ?? null,
            'volume_quality' => $confirmation['volume_quality'] ?? null,
            'structure_score' => $confirmation['structure_score'] ?? null,

            // Ranking stage
            'total_score' => $totalScore,
            'momentum_rank' => $ranking['momentum'] ?? null,
            'volume_rank' => $ranking['volume'] ?? null,
            'oi_rank' => $ranking['oi'] ?? null,
            'breakout_rank' => $ranking['breakout'] ?? null,
            'orderflow_rank' => $ranking['orderflow'] ?? null,
            'market_rank' => $ranking['market'] ?? null,

            // Market context
            'btc_price_at_signal' => $btcData['btc_price'] ?? null,
            'btc_change_1h' => $btcData['btc_change_1h'] ?? null,
            'market_session' => $session,

            // Classification
            'tier' => $tier,
            'source' => 'scanner',
            'outcome_tracked' => false,
        ]);

        Log::info("[SignalLogger] Saved signal #{$signal->id} {$symbol} score={$totalScore} tier={$tier}");

        return $signal->id;
    }

    public function logScanSummary(
        array $rankedCandidates,
        string $btcTrend = 'NEUTRAL',
        string $session = 'unknown',
        float $minScore = 70.0,
    ): int {
        $count = 0;
        foreach ($rankedCandidates as $candidate) {
            if (($candidate['total_score'] ?? 0) >= $minScore) {
                $this->logSignal(
                    symbol: $candidate['symbol'],
                    totalScore: $candidate['total_score'] ?? 0,
                    tier: $candidate['tier'] ?? 'WATCH',
                    discovery: [
                        'score' => $candidate['discovery_score'] ?? null,
                        'volume_acceleration' => $candidate['volume_acceleration'] ?? null,
                        'price_acceleration' => $candidate['price_acceleration'] ?? null,
                        'relative_volume' => $candidate['relative_volume'] ?? null,
                        'trade_count' => $candidate['trade_count'] ?? null,
                        'quote_volume' => $candidate['quote_volume'] ?? null,
                        'price_change_24h' => $candidate['price_change_24h'] ?? null,
                        'bid_ask_spread_pct' => $candidate['bid_ask_spread_pct'] ?? null,
                    ],
                    confirmation: [
                        'score' => $candidate['confirmation_score'] ?? null,
                        'momentum_score' => $candidate['momentum_score'] ?? null,
                        'volume_quality' => $candidate['volume_quality'] ?? null,
                        'structure_score' => $candidate['structure_score'] ?? null,
                    ],
                    ranking: [
                        'momentum' => $candidate['momentum_rank'] ?? null,
                        'volume' => $candidate['volume_rank'] ?? null,
                        'oi' => $candidate['oi_rank'] ?? null,
                        'breakout' => $candidate['breakout_rank'] ?? null,
                        'orderflow' => $candidate['orderflow_rank'] ?? null,
                        'market' => $candidate['market_rank'] ?? null,
                    ],
                    btcData: ['trend' => $btcTrend],
                    session: $session,
                );
                $count++;
            }
        }

        Log::info("[SignalLogger] Logged {$count} signals from scan (min_score={$minScore})");

        return $count;
    }

    public function getRecentSignals(?string $symbol = null, int $limit = 50): array
    {
        $query = Signal::query()->orderBy('id', 'desc')->limit($limit);
        if ($symbol) {
            $query->where('symbol', $symbol);
        }

        return $query->get()->toArray();
    }

    public function getSignalStats(): array
    {
        $total = Signal::count();
        $tracked = Signal::where('outcome_tracked', true)->count();

        $bySymbol = Signal::query()
            ->selectRaw('symbol, COUNT(*) as cnt')
            ->groupBy('symbol')
            ->orderBy('cnt', 'desc')
            ->pluck('cnt', 'symbol')
            ->toArray();

        $byTier = Signal::query()
            ->selectRaw('tier, COUNT(*) as cnt')
            ->groupBy('tier')
            ->pluck('cnt', 'tier')
            ->toArray();

        return [
            'total_signals' => $total,
            'tracked_outcomes' => $tracked,
            'by_symbol' => $bySymbol,
            'by_tier' => $byTier,
        ];
    }
}
