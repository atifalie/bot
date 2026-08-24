<?php

namespace App\Bot\MarketData;

/**
 * Converts raw ccxt OHLCV rows into a clean, validated CandleSeries. Never
 * throws for trading logic — always returns a report so the caller decides
 * whether to proceed (fail-safe design).
 */
class CandleValidator
{
    public function validateAndClean(
        array $rawOhlcv,
        string $timeframe,
        float $maxGapMultiplier = 2.5,
        float $maxNullPercent = 2.0,
    ): CandleFetchResult {
        if ($rawOhlcv === []) {
            return new CandleFetchResult(new CandleSeries, new DataQualityReport(false, 0, 100.0, 0, 0, 0.0, ['empty_response']));
        }

        $series = CandleSeries::fromRaw($rawOhlcv);
        $issues = [];

        $duplicates = $series->removeDuplicateTimestamps();
        if ($duplicates > 0) {
            $issues[] = "removed_{$duplicates}_duplicates";
        }

        $series->sortByTimestamp();

        $nullPercent = $series->nullPercent();
        if ($nullPercent > 0) {
            $issues[] = sprintf('null_percent_%.2f', $nullPercent);
        }

        $invalidCount = $series->removeInvalidPrices();
        if ($invalidCount > 0) {
            $issues[] = "invalid_prices_{$invalidCount}";
        }

        $gapStats = $this->detectGaps($series, $timeframe, $maxGapMultiplier);
        if ($gapStats['gaps'] > 0) {
            $issues[] = "gaps_detected_{$gapStats['gaps']}";
        }

        if ($nullPercent > 0 && $nullPercent <= $maxNullPercent) {
            $series->forwardFill();
        }

        $isValid = $series->count() > 0 && $nullPercent <= $maxNullPercent;

        return new CandleFetchResult($series, new DataQualityReport(
            isValid: $isValid,
            totalCandles: $series->count(),
            nullPercent: $nullPercent,
            duplicateCount: $duplicates,
            gapCount: $gapStats['gaps'],
            largestGapMultiplier: $gapStats['largest'],
            issues: $issues,
        ));
    }

    /** @return array{gaps: int, largest: float} */
    protected function detectGaps(CandleSeries $series, string $timeframe, float $maxGapMultiplier): array
    {
        $timestamps = $series->timestamps();
        $expectedMinutes = max(1, CandleSeries::timeframeToMinutes($timeframe));

        $gaps = 0;
        $largest = 1.0;

        for ($i = 1, $n = count($timestamps); $i < $n; $i++) {
            $diffMinutes = ($timestamps[$i] - $timestamps[$i - 1]) / 60000;
            $multiplier = $diffMinutes / $expectedMinutes;
            $largest = max($largest, $multiplier);

            if ($multiplier > $maxGapMultiplier) {
                $gaps++;
            }
        }

        return ['gaps' => $gaps, 'largest' => $largest];
    }
}
