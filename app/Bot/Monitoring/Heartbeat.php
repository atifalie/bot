<?php

namespace App\Bot\Monitoring;

use Illuminate\Support\Facades\Storage;

/**
 * Heartbeat / Watchdog — JSON file based health monitoring.
 * Port of monitoring/heartbeat.py — Laravel Storage instead of local file.
 */
class Heartbeat
{
    protected const FILE = 'bot_heartbeat.json';

    protected const STALE_SECONDS = 120;

    public static function update(
        int $cycleCount = 0,
        array $activeSymbols = [],
        array $openPositions = [],
        float $balance = 0,
        float $lastScanAgeSeconds = 0,
        int $apiErrors = 0,
        string $status = 'running',
    ): void {
        $now = now();
        $data = [
            'timestamp' => $now->toIso8601String(),
            'epoch' => $now->timestamp,
            'cycle_count' => $cycleCount,
            'active_symbols' => $activeSymbols,
            'open_positions' => $openPositions,
            'balance' => round($balance, 2),
            'last_scan_age_seconds' => round($lastScanAgeSeconds),
            'api_errors' => $apiErrors,
            'status' => $status,
        ];

        try {
            $tmp = self::FILE.'.tmp';
            Storage::disk('local')->put($tmp, json_encode($data));
            Storage::disk('local')->move($tmp, self::FILE);
        } catch (\Throwable $e) {
            \Log::warning("[Heartbeat] write failed: {$e->getMessage()}");
        }
    }

    public static function check(int $staleSeconds = self::STALE_SECONDS): array
    {
        if (! Storage::disk('local')->exists(self::FILE)) {
            return ['healthy' => false, 'age_seconds' => -1, 'details' => [], 'reason' => 'no_heartbeat_file'];
        }

        try {
            $data = json_decode(Storage::disk('local')->get(self::FILE), true);
            $heartbeatEpoch = $data['epoch'] ?? 0;
            $age = time() - $heartbeatEpoch;
            $healthy = $age < $staleSeconds;

            return [
                'healthy' => $healthy,
                'age_seconds' => round($age),
                'details' => $data,
                'reason' => $healthy ? 'ok' : "stale({$age}s > {$staleSeconds}s)",
            ];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'age_seconds' => -1, 'details' => [], 'reason' => "corrupt: {$e->getMessage()}"];
        }
    }

    public static function logStartup(string $version = 'v5'): void
    {
        \Log::info("Bot {$version} started at ".now()->toIso8601String());
        self::update(cycleCount: 0, activeSymbols: [], status: 'starting');
    }

    public static function logShutdown(string $reason = 'normal'): void
    {
        \Log::info("Bot shutting down: {$reason}");

        if (Storage::disk('local')->exists(self::FILE)) {
            try {
                $data = json_decode(Storage::disk('local')->get(self::FILE), true);
                $data['status'] = 'stopped';
                $data['stopped_at'] = now()->toIso8601String();
                Storage::disk('local')->put(self::FILE, json_encode($data));
            } catch (\Throwable) {
            }
        }
    }
}
