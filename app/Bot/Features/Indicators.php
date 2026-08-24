<?php

namespace App\Bot\Features;

use App\Bot\MarketData\CandleSeries;

/**
 * Pure indicator math over candles. Every function returns a full-length
 * array aligned with the input series; undefined windows hold NAN (mirroring
 * pandas semantics).
 */
class Indicators
{
    public static function computeAll(CandleSeries $series, array $cfg): FeatureSet
    {
        $closes = $series->closes();
        $features = new FeatureSet($series->count());

        // Moving averages + separation
        $fastMa = self::sma($closes, $cfg['fast_ma_period']);
        $slowMa = self::sma($closes, $cfg['slow_ma_period']);
        $sep = array_map(function ($f, $s) {
            if (is_nan($f) || is_nan($s) || $s == 0.0) {
                return NAN;
            }

            return (($f - $s) / $s) * 100;
        }, $fastMa, $slowMa);
        $features->set('fast_ma', $fastMa);
        $features->set('slow_ma', $slowMa);
        $features->set('ma_separation_pct', $sep);

        // RSI
        $features->set('rsi', self::rsi($closes, $cfg['rsi_period']));

        // ATR
        [$atr, $atrPct] = self::atr($series, $cfg['atr_period']);
        $features->set('atr', $atr);
        $features->set('atr_pct', $atrPct);

        // Volume
        $volumeMa = self::sma($series->volumes(), $cfg['volume_ma_period']);
        $relVolume = array_map(function ($v, $ma) {
            if (is_nan($ma) || $ma == 0.0) {
                return 1.0;
            }

            return $v / $ma;
        }, $series->volumes(), $volumeMa);
        $features->set('volume_ma', $volumeMa);
        $features->set('relative_volume', $relVolume);

        // MACD
        $emaFast = self::ema($closes, $cfg['macd_fast']);
        $emaSlow = self::ema($closes, $cfg['macd_slow']);
        $macdLine = array_map(fn ($f, $s) => $f - $s, $emaFast, $emaSlow);
        $macdSignal = self::ema($macdLine, $cfg['macd_signal']);
        $features->set('macd_line', $macdLine);
        $features->set('macd_signal', $macdSignal);
        $features->set('macd_histogram', array_map(fn ($l, $s) => $l - $s, $macdLine, $macdSignal));

        // Bollinger Bands
        [$bbMid, $bbUpper, $bbLower, $bbWidth, $bbPctB] = self::bollinger(
            $closes, $cfg['bb_period'], $cfg['bb_std_dev'],
        );
        $features->set('bb_mid', $bbMid);
        $features->set('bb_upper', $bbUpper);
        $features->set('bb_lower', $bbLower);
        $features->set('bb_width', $bbWidth);
        $features->set('bb_pct_b', $bbPctB);

        // VWAP
        [$vwap, $vwapDist] = self::vwap($series);
        $features->set('vwap', $vwap);
        $features->set('vwap_distance_pct', $vwapDist);

        // Stochastic RSI
        [$k, $d] = self::stochRsi($closes, $cfg['rsi_period'], $cfg['stoch_rsi_period']);
        $features->set('stoch_rsi_k', $k);
        $features->set('stoch_rsi_d', $d);

        // ADX (same period as ATR, matching upstream)
        [$adx, $plusDi, $minusDi] = self::adx($series, $cfg['atr_period']);
        $features->set('adx', $adx);
        $features->set('plus_di', $plusDi);
        $features->set('minus_di', $minusDi);

        return $features;
    }

    /**
     * Simple moving average; NAN until the window fills. Windows containing
     * NaN yield NaN without poisoning later windows.
     */
    public static function sma(array $values, int $window): array
    {
        $n = count($values);
        $out = array_fill(0, $n, NAN);
        $sum = 0.0;
        $nanCount = 0;

        foreach ($values as $i => $value) {
            if (is_nan($value)) {
                $nanCount++;
            } else {
                $sum += $value;
            }

            if ($i >= $window) {
                $old = $values[$i - $window];

                if (is_nan($old)) {
                    $nanCount--;
                } else {
                    $sum -= $old;
                }
            }

            if ($i >= $window - 1 && $nanCount === 0) {
                $out[$i] = $sum / $window;
            }
        }

        return $out;
    }

    /**
     * Exponential moving average with span semantics (alpha = 2/(span+1)),
     * seeded at the first finite value; NaN inputs carry forward the last mean.
     */
    public static function ema(array $values, float|int $span): array
    {
        $alpha = 2.0 / ($span + 1);
        $out = array_fill(0, count($values), NAN);
        $prev = null;

        foreach ($values as $i => $value) {
            if (is_nan($value)) {
                if ($prev !== null) {
                    $out[$i] = $prev;
                }

                continue;
            }

            $prev = $prev === null ? $value : $alpha * $value + (1 - $alpha) * $prev;
            $out[$i] = $prev;
        }

        return $out;
    }

    /**
     * RSI on simple rolling means of gains/losses.
     * Zero-loss windows resolve to 100 (pure uptrend) instead of collapsing
     * to the neutral fill — the one intentional fix versus upstream.
     */
    public static function rsi(array $closes, int $period): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, NAN);

        $gains = [0.0];
        $losses = [0.0];

        for ($i = 1; $i < $n; $i++) {
            $delta = $closes[$i] - $closes[$i - 1];
            $gains[$i] = max(0.0, $delta);
            $losses[$i] = max(0.0, -$delta);
        }

        $gainSum = array_sum(array_slice($gains, 0, $period));
        $lossSum = array_sum(array_slice($losses, 0, $period));

        for ($i = $period - 1; $i < $n; $i++) {
            if ($i > $period - 1) {
                $gainSum += $gains[$i] - $gains[$i - $period];
                $lossSum += $losses[$i] - $losses[$i - $period];
            }

            $avgGain = $gainSum / $period;
            $avgLoss = $lossSum / $period;

            $out[$i] = match (true) {
                $avgLoss == 0.0 && $avgGain == 0.0 => 50.0,
                $avgLoss == 0.0 => 100.0,
                default => 100.0 - (100.0 / (1.0 + $avgGain / $avgLoss)),
            };
        }

        return $out;
    }

    /** @return array{0: list<float>, 1: list<float>} [atr, atr_pct] */
    public static function atr(CandleSeries $series, int $period): array
    {
        $highs = $series->highs();
        $lows = $series->lows();
        $closes = $series->closes();
        $n = count($closes);

        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $tr[] = $highs[0] - $lows[0];

                continue;
            }

            $tr[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        $atr = self::sma($tr, $period);
        $atrPct = array_map(function ($a, $c) {
            if (is_nan($a) || is_nan($c) || $c == 0.0) {
                return NAN;
            }

            return ($a / $c) * 100;
        }, $atr, $closes);

        return [$atr, $atrPct];
    }

    /** @return array{0: list<float>, 1: list<float>, 2: list<float>, 3: list<float>, 4: list<float>} */
    public static function bollinger(array $closes, int $period, float $stdDev): array
    {
        $mid = self::sma($closes, $period);
        $std = self::rollingStd($closes, $period);

        $upper = [];
        $lower = [];
        $width = [];
        $pctB = [];

        foreach ($closes as $i => $close) {
            if (is_nan($mid[$i]) || is_nan($std[$i])) {
                $upper[] = NAN;
                $lower[] = NAN;
                $width[] = NAN;
                $pctB[] = NAN;

                continue;
            }

            $u = $mid[$i] + $std[$i] * $stdDev;
            $l = $mid[$i] - $std[$i] * $stdDev;
            $range = $u - $l;

            $upper[] = $u;
            $lower[] = $l;
            $width[] = $range == 0.0 ? NAN : ($range / $mid[$i]) * 100;
            $pctB[] = $range == 0.0 ? NAN : ($close - $l) / $range;
        }

        return [$mid, $upper, $lower, $width, $pctB];
    }

    /** @return array{0: list<float>, 1: list<float>} */
    public static function vwap(CandleSeries $series): array
    {
        $vwapCol = [];
        $distCol = [];
        $cumVol = 0.0;
        $cumTpVol = 0.0;

        foreach ($series->candles() as $i => $candle) {
            $typical = ($candle->high + $candle->low + $candle->close) / 3;
            $cumVol += $candle->volume;
            $cumTpVol += $typical * $candle->volume;

            if ($cumVol == 0.0) {
                $vwapCol[] = NAN;
                $distCol[] = NAN;

                continue;
            }

            $v = $cumTpVol / $cumVol;
            $vwapCol[] = $v;
            $distCol[] = $v == 0.0 ? NAN : (($candle->close - $v) / $v) * 100;
        }

        return [$vwapCol, $distCol];
    }

    /** @return array{0: list<float>, 1: list<float>} */
    public static function stochRsi(array $closes, int $rsiPeriod, int $stochPeriod, int $kSmooth = 3, int $dSmooth = 3): array
    {
        $rsi = self::rsi($closes, $rsiPeriod);
        $n = count($closes);

        $kRaw = array_fill(0, $n, NAN);

        for ($i = 0; $i < $n; $i++) {
            $from = $i - $stochPeriod + 1;

            if ($from < 0 || is_nan($rsi[$i])) {
                continue;
            }

            $window = array_slice($rsi, $from, $stochPeriod);

            if (in_array(true, array_map('is_nan', $window), true)) {
                continue;
            }

            $min = min($window);
            $max = max($window);
            $range = $max - $min;

            $kRaw[$i] = $range == 0.0 ? NAN : (($rsi[$i] - $min) / $range) * 100;
        }

        $k = self::sma($kRaw, $kSmooth);
        $d = self::sma($k, $dSmooth);

        return [$k, $d];
    }

    /**
     * @return array{0: list<float>, 1: list<float>, 2: list<float>} [adx, plus_di, minus_di]
     */
    public static function adx(CandleSeries $series, int $period): array
    {
        $highs = $series->highs();
        $lows = $series->lows();
        $closes = $series->closes();
        $n = count($closes);

        $tr = [];
        $plusDm = [];
        $minusDm = [];

        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $tr[] = $highs[0] - $lows[0];
                $plusDm[] = 0.0;
                $minusDm[] = 0.0;

                continue;
            }

            $upMove = $highs[$i] - $highs[$i - 1];
            $downMove = $lows[$i - 1] - $lows[$i];

            $tr[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
        }

        $smoothedAtr = self::ema($tr, $period);
        $smoothedPlus = self::ema($plusDm, $period);
        $smoothedMinus = self::ema($minusDm, $period);

        $plusDi = [];
        $minusDi = [];

        for ($i = 0; $i < $n; $i++) {
            $a = $smoothedAtr[$i];

            $plusDi[] = ($a == 0.0 || is_nan($a)) ? NAN : (100.0 * $smoothedPlus[$i]) / $a;
            $minusDi[] = ($a == 0.0 || is_nan($a)) ? NAN : (100.0 * $smoothedMinus[$i]) / $a;
        }

        $dx = [];
        for ($i = 0; $i < $n; $i++) {
            $sum = $plusDi[$i] + $minusDi[$i];
            $dx[] = is_nan($sum) || $sum == 0.0
                ? NAN
                : (100.0 * abs($plusDi[$i] - $minusDi[$i])) / $sum;
        }

        return [self::ema($dx, $period), $plusDi, $minusDi];
    }

    /** Sample standard deviation (ddof=1), full non-NaN window required. */
    public static function rollingStd(array $values, int $window): array
    {
        $n = count($values);
        $out = array_fill(0, $n, NAN);

        for ($i = $window - 1; $i < $n; $i++) {
            $slice = array_slice($values, $i - $window + 1, $window);

            if (in_array(true, array_map('is_nan', $slice), true)) {
                continue;
            }

            $mean = array_sum($slice) / $window;
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $slice)) / ($window - 1);
            $out[$i] = sqrt($variance);
        }

        return $out;
    }
}
