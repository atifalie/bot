<?php

namespace App\Bot\Support;

/**
 * Price display formatter — micro-cap coins (0.0164) ko "0.02" round hone
 * se bachata hai. Magnitude ke hisaab se decimals badalta hai.
 */
class PriceFormatter
{
    public static function format(float|string|null $price): string
    {
        $p = (float) $price;

        if ($p == 0.0) {
            return '0.00';
        }

        return match (true) {
            abs($p) >= 1000 => number_format($p, 2),
            abs($p) >= 10 => number_format($p, 3),
            abs($p) >= 1 => number_format($p, 4),
            abs($p) >= 0.01 => number_format($p, 5),
            default => number_format($p, 7),
        };
    }
}
