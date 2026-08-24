<?php

namespace App\Bot\Streaming;

/**
 * In-memory candle store for the streaming daemon.
 *
 * Holds confirmed candles per symbol+timeframe (ccxt ohlcv format:
 * [ts, open, high, low, close, volume]) plus the currently forming
 * candle. Seeded from REST history, then updated by WS kline events.
 */
class CandleBuffer
{
    /** @var array<string, array<int, array{0:int,1:float,2:float,3:float,4:float,5:float}>> */
    protected array $candles = [];

    /** @var array<string, array{0:int,1:float,2:float,3:float,4:float,5:float}|null> forming (unconfirmed) candle */
    protected array $forming = [];

    public function __construct(
        protected int $maxCandles = 400,
    ) {}

    protected function key(string $symbol, string $timeframe): string
    {
        return $symbol.'|'.$timeframe;
    }

    /**
     * Seed/replace history (REST bootstrap or post-reconnect repair).
     *
     * @param  array<int, array{0:int,1:float,2:float,3:float,4:float,5:float}>  $ohlcv
     */
    public function seed(string $symbol, string $timeframe, array $ohlcv): void
    {
        $k = $this->key($symbol, $timeframe);
        $this->candles[$k] = [];
        $this->forming[$k] = null;

        foreach ($ohlcv as $candle) {
            $this->upsert($symbol, $timeframe, $candle, confirmed: true);
        }
    }

    /**
     * Upsert a candle from a WS kline event. Confirmed candles are appended
     * into the series; unconfirmed ones live in the "forming" slot only.
     *
     * @param  array{0:int,1:float,2:float,3:float,4:float,5:float}  $candle
     */
    public function upsert(string $symbol, string $timeframe, array $candle, bool $confirmed): void
    {
        if ($candle[0] <= 0 || $candle[4] <= 0) {
            return; // malformed guard
        }

        $k = $this->key($symbol, $timeframe);

        if ($confirmed) {
            // A confirm may arrive right after we already stored it — keep first version.
            if (($this->forming[$k][0] ?? null) === $candle[0]) {
                $this->forming[$k] = null;
            }
            $this->appendToSeries($k, $candle);

            return;
        }

        // Forming candle newer than our last confirmed? Track it separately.
        $series = $this->candles[$k] ?? [];
        $lastTs = $series === [] ? 0 : $series[array_key_last($series)][0];
        if ($candle[0] > $lastTs) {
            $this->forming[$k] = $candle;
        }
    }

    protected function appendToSeries(string $k, array $candle): void
    {
        $series = &$this->candles[$k];
        if (! isset($series)) {
            $series = [];
        }

        $lastIdx = $series === [] ? null : array_key_last($series);

        if ($lastIdx !== null && $series[$lastIdx][0] === $candle[0]) {
            $series[$lastIdx] = $candle;

            return;
        }

        if ($lastIdx !== null && $candle[0] < $series[$lastIdx][0]) {
            return; // stale/out-of-order — ignore
        }

        $series[] = $candle;

        if (count($series) > $this->maxCandles) {
            $series = array_slice($series, -$this->maxCandles);
        }
    }

    /**
     * Confirmed candles as ccxt-style ohlcv array (no forming candle).
     *
     * @return array<int, array{0:int,1:float,2:float,3:float,4:float,5:float}>
     */
    public function ohlcv(string $symbol, string $timeframe): array
    {
        return $this->candles[$this->key($symbol, $timeframe)] ?? [];
    }

    public function lastPrice(string $symbol): float
    {
        $k = $this->key($symbol, '1m');
        foreach (['1m', '5m', '15m', '1h'] as $tf) {
            $series = $this->candles[$this->key($symbol, $tf)] ?? [];
            if ($series !== []) {
                return (float) $series[array_key_last($series)][4];
            }
        }

        return 0.0;
    }

    public function isSeeded(string $symbol, string $timeframe): bool
    {
        return ($this->candles[$this->key($symbol, $timeframe)] ?? []) !== [];
    }
}
