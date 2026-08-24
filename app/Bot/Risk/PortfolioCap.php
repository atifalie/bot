<?php

namespace App\Bot\Risk;

use App\Bot\Trading\StateStore;
use Illuminate\Support\Facades\Config;

/**
 * Portfolio-level position cap.
 *
 * Jab open positions == max_open_trades: flat coins ki entry-search band,
 * lekin open positions ke EXIT/trailing normal manage hote rahenge.
 */
class PortfolioCap
{
    /**
     * @param  array<string>  $symbols
     */
    public static function openCount(StateStore $store, array $symbols): int
    {
        $open = 0;
        foreach ($symbols as $symbol) {
            if ($store->loadPosition($symbol)['in_position'] ?? false) {
                $open++;
            }
        }

        return $open;
    }

    public static function max(): int
    {
        return (int) Config::get('bot.risk.max_open_trades', 5);
    }

    /**
     * @param  array<string>  $symbols
     */
    public static function canOpenNew(StateStore $store, array $symbols): bool
    {
        return self::openCount($store, $symbols) < self::max();
    }
}
