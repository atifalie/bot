<?php

namespace App\Bot\MarketData;

/**
 * Immutable single OHLCV candle.
 */
final class Candle
{
    public function __construct(
        public readonly int $timestampMs,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume,
    ) {}

    /** @param array<int|string, mixed> $row ccxt row [ts, o, h, l, c, v] */
    public static function fromRow(array $row): ?self
    {
        $values = array_values($row);

        if (count($values) < 6) {
            return null;
        }

        return new self(
            (int) $values[0],
            is_numeric($values[1]) ? (float) $values[1] : NAN,
            is_numeric($values[2]) ? (float) $values[2] : NAN,
            is_numeric($values[3]) ? (float) $values[3] : NAN,
            is_numeric($values[4]) ? (float) $values[4] : NAN,
            is_numeric($values[5]) ? (float) $values[5] : NAN,
        );
    }

    public function isValidPrice(): bool
    {
        return ! ($this->close <= 0 || $this->open <= 0 || $this->high < $this->low);
    }
}
