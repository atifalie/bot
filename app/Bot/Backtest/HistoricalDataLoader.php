<?php

namespace App\Bot\Backtest;

use App\Bot\Exchange\Trader;
use App\Bot\MarketData\CandleSeries;

/**
 * Loads historical OHLCV with pagination (Bybit caps at 1000/call) and
 * caches to disk so repeated backtests never re-hit the exchange.
 * Rows stay in raw ccxt format: [ts, open, high, low, close, volume].
 */
class HistoricalDataLoader
{
    public function __construct(
        protected Trader $trader,
    ) {}

    /**
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float}>
     */
    public function load(string $symbol, string $timeframe, int $days): array
    {
        $file = $this->cachePath($symbol, $timeframe, $days);

        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            if (is_array($data) && count($data) > 100) {
                return $data;
            }
        }

        $rows = $this->fetchPaginated($symbol, $timeframe, $days);

        if (count($rows) < 100) {
            throw new \RuntimeException("[{$symbol} {$timeframe}] only ".count($rows).' candles fetched — aborting');
        }

        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode($rows));

        return $rows;
    }

    protected function fetchPaginated(string $symbol, string $timeframe, int $days): array
    {
        $tfMs = CandleSeries::timeframeToMinutes($timeframe) * 60_000;
        $since = (int) ((microtime(true) * 1000)) - ($days * 86_400_000);
        $cursor = $since;
        $all = [];

        while (true) {
            $batch = $this->trader->fetchOhlcvSince($symbol, $timeframe, $cursor, 1000);
            $batch = array_values(array_filter($batch, fn ($r) => is_array($r) && ($r[0] ?? 0) >= $cursor));

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $all[(int) $row[0]] = $row;
            }

            $lastTs = (int) end($batch)[0];
            if ($lastTs + $tfMs >= microtime(true) * 1000 || count($batch) < 10) {
                break;
            }

            $cursor = $lastTs + $tfMs;
            usleep(350_000); // BanGuard-friendly pacing
        }

        ksort($all);

        return array_values($all);
    }

    protected function cachePath(string $symbol, string $timeframe, int $days): string
    {
        $safe = str_replace(['/', '\\'], '_', $symbol);

        return storage_path("app/backtest/{$safe}_{$timeframe}_{$days}d.json");
    }
}
