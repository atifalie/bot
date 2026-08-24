<?php

namespace App\Bot\Memory;

use App\Models\Pattern;
use App\Models\Signal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pattern Engine — discovers patterns from historical signals/outcomes.
 * Port of memory/pattern_engine.py — MySQL instead of SQLite.
 */
class PatternEngine
{
    protected const BIN_RULES = [
        'volume_acceleration' => [
            [0, 1.0, 'low'], [1.0, 2.0, 'moderate'], [2.0, 4.0, 'high'], [4.0, 100, 'extreme'],
        ],
        'momentum_rank' => [
            [0, 10, 'weak'], [10, 20, 'moderate'], [20, 30, 'strong'],
        ],
        'oi_rank' => [
            [0, 5, 'low'], [5, 10, 'moderate'], [10, 15, 'high'],
        ],
        'breakout_rank' => [
            [0, 5, 'no'], [5, 10, 'forming'], [10, 15, 'yes'],
        ],
        'orderflow_rank' => [
            [0, 5, 'sell_pressure'], [5, 8, 'neutral'], [8, 10, 'buy_pressure'],
        ],
        'total_score' => [
            [0, 60, 'low'], [60, 75, 'medium'], [75, 85, 'high'], [85, 100, 'very_high'],
        ],
    ];

    public function discoverPatterns(int $minSample = 10): array
    {
        $signals = Signal::query()
            ->join('signal_outcomes', 'signals.id', '=', 'signal_outcomes.signal_id')
            ->where('signals.outcome_tracked', true)
            ->orderBy('signals.signaled_at', 'desc')
            ->get([
                'signals.*',
                'signal_outcomes.reached_5pct',
                'signal_outcomes.reached_10pct',
                'signal_outcomes.reached_minus_5pct',
                'signal_outcomes.max_gain_1h',
                'signal_outcomes.max_drawdown_1h',
                'signal_outcomes.max_gain_24h',
            ]);

        $signals = collect($signals);

        if ($signals->count() < $minSample) {
            Log::info("[PatternEngine] Not enough tracked signals ({$signals->count()}) for pattern discovery. Need {$minSample}.");

            return [];
        }

        Log::info("[PatternEngine] Discovering patterns from {$signals->count()} tracked signals...");

        $grouped = [];

        foreach ($signals as $sig) {
            $bins = $this->signalToBins($sig->toArray());

            if ($sig->btc_trend) {
                $bins['btc_trend'] = $sig->btc_trend;
            }
            if ($sig->tier) {
                $bins['tier'] = $sig->tier;
            }

            $key = json_encode($bins, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! isset($grouped[$key])) {
                $grouped[$key] = ['bins' => $bins, 'signals' => []];
            }
            $grouped[$key]['signals'][] = $sig;
        }

        $patterns = [];

        foreach ($grouped as $key => $group) {
            $sigs = collect($group['signals']);
            $total = $sigs->count();

            if ($total < $minSample) {
                continue;
            }

            $win5 = $sigs->filter(fn ($s) => $s->reached_5pct)->count();
            $win10 = $sigs->filter(fn ($s) => $s->reached_10pct)->count();
            $loss5 = $sigs->filter(fn ($s) => $s->reached_minus_5pct)->count();

            $gains = $sigs->pluck('max_gain_1h')->filter()->toArray();
            $drawdowns = $sigs->pluck('max_drawdown_1h')->filter()->toArray();
            $gains24h = $sigs->pluck('max_gain_24h')->filter()->toArray();

            $avgGain = $gains !== [] ? array_sum($gains) / count($gains) : 0;
            $avgDd = $drawdowns !== [] ? array_sum($drawdowns) / count($drawdowns) : 0;
            $avgGain24h = $gains24h !== [] ? array_sum($gains24h) / count($gains24h) : 0;
            $bestGain = $gains !== [] ? max($gains) : 0;
            $worstDd = $drawdowns !== [] ? max($drawdowns) : 0;

            $confidence = $total >= 100 ? 'HIGH' : ($total >= 30 ? 'MEDIUM' : 'LOW');

            $patterns[] = [
                'conditions' => $group['bins'],
                'total_signals' => $total,
                'win_count' => $win5,
                'win_rate' => round(($win5 / $total) * 100, 1),
                'avg_gain' => round($avgGain, 2),
                'avg_drawdown' => round($avgDd, 2),
                'best_gain' => round($bestGain, 2),
                'worst_drawdown' => round($worstDd, 2),
                'sample_size' => $total,
                'confidence' => $confidence,
            ];
        }

        usort($patterns, fn ($a, $b) => $b['win_rate'] <=> $a['win_rate']);

        $this->savePatterns($patterns);

        Log::info('[PatternEngine] Discovered '.count($patterns).' patterns');

        return $patterns;
    }

    protected function savePatterns(array $patterns): void
    {
        DB::transaction(function () use ($patterns) {
            Pattern::query()->delete();
            $now = now();

            foreach ($patterns as $p) {
                Pattern::create([
                    'discovered_at' => $now,
                    'conditions' => $p['conditions'],
                    'total_signals' => $p['total_signals'],
                    'win_count' => $p['win_count'],
                    'win_rate' => $p['win_rate'],
                    'avg_gain' => $p['avg_gain'],
                    'avg_drawdown' => $p['avg_drawdown'],
                    'best_gain' => $p['best_gain'],
                    'worst_drawdown' => $p['worst_drawdown'],
                    'sample_size' => $p['sample_size'],
                    'confidence' => $p['confidence'],
                ]);
            }
        });
    }

    public function scoreSignal(array $signalData): array
    {
        $bins = $this->signalToBins($signalData);

        if (! empty($signalData['btc_trend'])) {
            $bins['btc_trend'] = $signalData['btc_trend'];
        }
        if (! empty($signalData['tier'])) {
            $bins['tier'] = $signalData['tier'];
        }

        $patterns = Pattern::query()
            ->where('sample_size', '>=', 10)
            ->orderBy('win_rate', 'desc')
            ->get();

        $matching = [];

        foreach ($patterns as $p) {
            $conditions = $p->conditions;
            $match = true;

            foreach ($conditions as $field => $requiredBin) {
                if (($bins[$field] ?? null) !== $requiredBin) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                $matching[] = [
                    'win_rate' => $p->win_rate,
                    'avg_gain' => $p->avg_gain,
                    'sample_size' => $p->sample_size,
                    'confidence' => $p->confidence,
                ];
            }
        }

        if ($matching === []) {
            return [
                'pattern_score' => 50,
                'matching_patterns' => [],
                'recommendation' => 'NO_DATA',
                'message' => 'No matching patterns found',
            ];
        }

        $best = $matching[0];
        $patternScore = min(100, round($best['win_rate'] * 1.1, 1));

        if ($patternScore >= 80 && in_array($best['confidence'], ['HIGH', 'MEDIUM'], true)) {
            $recommendation = 'STRONG_BUY';
        } elseif ($patternScore >= 65) {
            $recommendation = 'BUY';
        } elseif ($patternScore >= 50) {
            $recommendation = 'WATCH';
        } else {
            $recommendation = 'AVOID';
        }

        return [
            'pattern_score' => $patternScore,
            'matching_patterns' => $matching,
            'recommendation' => $recommendation,
            'best_match_win_rate' => $best['win_rate'],
            'best_match_sample' => $best['sample_size'],
            'message' => count($matching).' patterns match, best win rate: '.number_format($best['win_rate'], 1).'%',
        ];
    }

    public function getPatternReport(int $limit = 15): string
    {
        $patterns = Pattern::query()
            ->where('sample_size', '>=', 10)
            ->orderBy('win_rate', 'desc')
            ->limit($limit)
            ->get();

        if ($patterns->isEmpty()) {
            return 'No patterns discovered yet. Need more historical signals.';
        }

        $lines = ["=== PATTERN MEMORY REPORT ===\n"];

        foreach ($patterns as $i => $p) {
            $conditions = $p->conditions;
            $condStr = implode(' AND ', array_map(fn ($v, $k) => "{$k}={$v}", $conditions, array_keys($conditions)));

            $emoji = match (true) {
                $p->win_rate >= 70 => '🔥',
                $p->win_rate >= 60 => '🚀',
                $p->win_rate >= 50 => '👀',
                default => '⚠️',
            };

            $lines[] = "{$emoji} Pattern #".($i + 1).": {$condStr}";
            $lines[] = "   Win Rate: {$p->win_rate}% | Avg Gain: {$p->avg_gain}% | Sample: {$p->sample_size}";
            $lines[] = "   Confidence: {$p->confidence}\n";
        }

        return implode("\n", $lines);
    }

    protected function signalToBins(array $signal): array
    {
        $bins = [];

        foreach (self::BIN_RULES as $field => $rules) {
            $value = $signal[$field] ?? null;

            if ($value === null) {
                continue;
            }

            $value = (float) $value;

            foreach ($rules as $rule) {
                [$low, $high, $label] = $rule;
                if ($low <= $value && $value < $high) {
                    $bins[$field] = $label;
                    break;
                }
            }
        }

        return $bins;
    }
}
