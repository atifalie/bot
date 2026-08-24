<?php

namespace App\Bot\MarketData;

use RuntimeException;

/**
 * Column-oriented view over candles — replaces the pandas DataFrame the
 * Python version leaned on, without any external dependency.
 */
class CandleSeries
{
    /** @param list<Candle> $candles */
    public function __construct(protected array $candles = []) {}

    /** @param list<array<int|string, mixed>> $rawOhlcv raw ccxt rows */
    public static function fromRaw(array $rawOhlcv): self
    {
        $candles = [];
        foreach ($rawOhlcv as $row) {
            $candle = Candle::fromRow((array) $row);
            if ($candle !== null) {
                $candles[] = $candle;
            }
        }

        return new self($candles);
    }

    /** @return list<Candle> */
    public function candles(): array
    {
        return $this->candles;
    }

    public function count(): int
    {
        return count($this->candles);
    }

    public function isEmpty(): bool
    {
        return $this->candles === [];
    }

    public function first(): ?Candle
    {
        return $this->candles[0] ?? null;
    }

    public function last(): ?Candle
    {
        return $this->candles === [] ? null : $this->candles[count($this->candles) - 1];
    }

    public function at(int $index): Candle
    {
        return $this->candles[$index] ?? throw new RuntimeException("Candle index {$index} out of range");
    }

    /** @return list<float> */
    public function closes(): array
    {
        return array_map(fn (Candle $c) => $c->close, $this->candles);
    }

    /** @return list<float> */
    public function opens(): array
    {
        return array_map(fn (Candle $c) => $c->open, $this->candles);
    }

    /** @return list<float> */
    public function highs(): array
    {
        return array_map(fn (Candle $c) => $c->high, $this->candles);
    }

    /** @return list<float> */
    public function lows(): array
    {
        return array_map(fn (Candle $c) => $c->low, $this->candles);
    }

    /** @return list<float> */
    public function volumes(): array
    {
        return array_map(fn (Candle $c) => $c->volume, $this->candles);
    }

    /** @return list<int> epoch-ms timestamps */
    public function timestamps(): array
    {
        return array_map(fn (Candle $c) => $c->timestampMs, $this->candles);
    }

    /**
     * Removes the still-forming last candle so signals are computed on closed
     * data only (anti-repainting).
     */
    public function dropFormingCandle(string $timeframe): self
    {
        $last = $this->last();

        if ($last === null) {
            return $this;
        }

        $intervalMs = self::timeframeToMinutes($timeframe) * 60 * 1000;

        if (($last->timestampMs + $intervalMs) > microtime(true) * 1000) {
            return new self(array_slice($this->candles, 0, -1));
        }

        return $this;
    }

    public function sortByTimestamp(): self
    {
        usort($this->candles, fn (Candle $a, Candle $b) => $a->timestampMs <=> $b->timestampMs);

        return $this;
    }

    public function removeDuplicateTimestamps(): int
    {
        $seen = [];

        // Walk newest→oldest so the LAST occurrence of each timestamp wins.
        $kept = [];
        for (end($this->candles); key($this->candles) !== null; prev($this->candles)) {
            $candle = current($this->candles);

            if (! isset($seen[$candle->timestampMs])) {
                $seen[$candle->timestampMs] = true;
                $kept[] = $candle;
            }
        }

        $removed = $this->count() - count($kept);
        $this->candles = array_reverse($kept);

        return $removed;
    }

    public function removeInvalidPrices(): int
    {
        $before = $this->count();
        $this->candles = array_values(array_filter($this->candles, fn (Candle $c) => $c->isValidPrice()));

        return $before - $this->count();
    }

    public function nullPercent(): float
    {
        if ($this->isEmpty()) {
            return 100.0;
        }

        $fields = 5;
        $nulls = 0;

        foreach ($this->candles as $candle) {
            foreach ([$candle->open, $candle->high, $candle->low, $candle->close, $candle->volume] as $value) {
                if (is_nan($value)) {
                    $nulls++;
                }
            }
        }

        return ($nulls / ($this->count() * $fields)) * 100;
    }

    /**
     * Forward-fills NaN values from the previous candle (isolated gaps only;
     * systemic data loss stays visible in the quality report).
     */
    public function forwardFill(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        foreach ($this->candles as $i => $candle) {
            $prev = $this->candles[$i - 1] ?? null;

            if ($prev === null) {
                continue;
            }

            $hasNull = is_nan($candle->open) || is_nan($candle->high) || is_nan($candle->low)
                || is_nan($candle->close) || is_nan($candle->volume);

            if (! $hasNull) {
                continue;
            }

            $this->candles[$i] = new Candle(
                $candle->timestampMs,
                is_nan($candle->open) ? $prev->open : $candle->open,
                is_nan($candle->high) ? $prev->high : $candle->high,
                is_nan($candle->low) ? $prev->low : $candle->low,
                is_nan($candle->close) ? $prev->close : $candle->close,
                is_nan($candle->volume) ? $prev->volume : $candle->volume,
            );
        }
    }

    public static function timeframeToMinutes(string $timeframe): int
    {
        $unit = substr($timeframe, -1);
        $value = (int) substr($timeframe, 0, -1);

        $multiplier = match ($unit) {
            'm' => 1,
            'h' => 60,
            'd' => 60 * 24,
            default => throw new \InvalidArgumentException("Unsupported timeframe format: {$timeframe}"),
        };

        return $value * $multiplier;
    }
}
