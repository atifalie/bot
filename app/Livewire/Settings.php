<?php

namespace App\Livewire;

use Livewire\Component;

class Settings extends Component
{
    public array $symbols = [];

    public string $newSymbol = '';

    public string $timeframe = '15m';

    public string $higherTimeframe = '1h';

    public int $minConfidence = 65;

    public ?string $statusMessage = null;

    public ?bool $statusOk = true;

    public const TIMEFRAMES = ['3m', '5m', '15m', '30m', '1h', '2h', '4h', '1d'];

    public const MAX_SYMBOLS = 25;

    protected const HTF_MAP = [
        '3m' => '15m',
        '5m' => '30m',
        '15m' => '1h',
        '30m' => '2h',
        '1h' => '4h',
        '2h' => '4h',
        '4h' => '1d',
        '1d' => '1d',
    ];

    public function mount(): void
    {
        $this->symbols = config('bot.market.symbols', []);
        $this->timeframe = config('bot.market.timeframe', '15m');
        $this->higherTimeframe = config('bot.market.higher_timeframe', '1h');
        $this->minConfidence = (int) config('bot.validation.min_confidence_to_act', 65);
    }

    public function addSymbol(): void
    {
        $this->statusMessage = null;

        $raw = trim($this->newSymbol);

        if ($raw === '') {
            return;
        }

        $p = $this->parseCoins($raw, replace: false);

        $this->symbols = array_values([...$this->symbols, ...$p['valid']]);
        $this->newSymbol = '';

        $notes = [];
        $notes[] = $p['valid'] !== [] ? '➕ Added '.count($p['valid']).': '.implode(', ', $p['valid']) : null;
        $notes[] = $p['duplicates'] !== [] ? '⚠️ Already in list: '.implode(', ', $p['duplicates']) : null;
        $notes[] = $p['invalid'] !== [] ? '❌ Invalid format (BTC/USDT chahiye): '.implode(', ', $p['invalid']) : null;
        $notes[] = $p['limitHit'] ? '🛑 Max '.self::MAX_SYMBOLS.' coins — ye add nahi hue (limit)' : null;

        if ($p['valid'] === []) {
            $this->statusOk = false;
        } else {
            $this->statusOk = $p['invalid'] === [] && ! $p['limitHit'];
            $notes[] = 'Save dabana zaroori hai.';
        }

        $this->statusMessage = implode(' · ', array_filter($notes)) ?: 'Kuch add nahi hua.';
    }

    /**
     * Poori list ek saath REPLACE — purani list udd jati hai, input wali set hoti hai.
     */
    public function replaceAllSymbols(): void
    {
        $this->statusMessage = null;

        $raw = trim($this->newSymbol);

        if ($raw === '') {
            return;
        }

        $p = $this->parseCoins($raw, replace: true);

        if ($p['valid'] === []) {
            $this->statusOk = false;
            $this->statusMessage = '❌ Kuch valid nahi mila — format: BTC, SOL, XRP (comma separated).';

            return;
        }

        $this->symbols = $p['valid'];
        $this->newSymbol = '';

        $notes = [];
        $notes[] = '🔄 Replaced — '.count($p['valid']).' coins: '.implode(', ', $p['valid']);
        $notes[] = $p['invalid'] !== [] ? '❌ Invalid/skipped: '.implode(', ', $p['invalid']) : null;
        $notes[] = $p['limitHit'] ? '🛑 Max '.self::MAX_SYMBOLS.' ke baad skip' : null;
        $notes[] = 'Save dabana zaroori hai.';

        $this->statusOk = $p['invalid'] === [] && ! $p['limitHit'];
        $this->statusMessage = implode(' · ', array_filter($notes));
    }

    /**
     * "BTC, sol/USDT, ETHUSDT" → normalized coin list with duplicate/invalid/limit tracking.
     */
    private function parseCoins(string $raw, bool $replace): array
    {
        $valid = [];
        $invalid = [];
        $duplicates = [];
        $limitHit = false;
        $baseCount = $replace ? 0 : count($this->symbols);

        foreach (explode(',', trim($raw)) as $part) {
            $coin = strtoupper(trim($part));

            if ($coin === '') {
                continue;
            }

            // Normalize: "btc"→BTC/USDT, "BTCUSDT"→BTC/USDT, "PEPE"→PEPE/USDT
            if (! str_contains($coin, '/')) {
                if (str_ends_with($coin, 'USDT') && strlen($coin) > 4) {
                    $coin = substr($coin, 0, -4);
                }

                if (! preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $coin)) {
                    $invalid[] = $coin;

                    continue;
                }

                $coin .= '/USDT';
            }

            if (in_array($coin, $valid) || (! $replace && in_array($coin, $this->symbols))) {
                $duplicates[] = $coin;

                continue;
            }

            if ($baseCount + count($valid) >= self::MAX_SYMBOLS) {
                $limitHit = true;

                continue;
            }

            $valid[] = $coin;
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'duplicates' => $duplicates, 'limitHit' => $limitHit];
    }

    public function removeSymbol(string $symbol): void
    {
        $this->symbols = array_values(array_diff($this->symbols, [$symbol]));
        $this->statusOk = true;
        $this->statusMessage = "➖ {$symbol} removed — Save dabana zaroori hai.";
    }

    public function setTimeframe(string $tf): void
    {
        if (! in_array($tf, self::TIMEFRAMES)) {
            return;
        }

        $this->timeframe = $tf;
        $this->higherTimeframe = self::HTF_MAP[$tf] ?? '1h';
    }

    public function setHigherTimeframe(string $htf): void
    {
        if (in_array($htf, self::TIMEFRAMES)) {
            $this->higherTimeframe = $htf;
        }
    }

    public function save(): void
    {
        $this->statusMessage = null;

        if ($this->symbols === []) {
            $this->statusOk = false;
            $this->statusMessage = '❌ Kam az kam ek coin chahiye.';

            return;
        }

        $min = max(0, min(95, (int) $this->minConfidence));

        try {
            $this->writeEnv([
                'BOT_SYMBOLS' => implode(',', $this->symbols),
                'BOT_TIMEFRAME' => $this->timeframe,
                'BOT_HIGHER_TIMEFRAME' => $this->higherTimeframe,
                'MIN_CONFIDENCE' => (string) $min,
            ]);
        } catch (\Throwable $e) {
            $this->statusOk = false;
            $this->statusMessage = '❌ Save failed: '.str($e->getMessage())->limit(80);

            return;
        }

        $this->minConfidence = $min;

        $this->statusOk = true;
        $this->statusMessage = '✅ Saved! Agla bot cycle naye settings se chalega.';
    }

    /**
     * Update keys in .env in place (append missing ones).
     */
    protected function writeEnv(array $values): void
    {
        $path = app()->environmentFilePath();
        $content = file_get_contents($path);

        foreach ($values as $key => $value) {
            $pattern = "/^{$key}=.*$/m";

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$value}", $content);
            } else {
                $content = rtrim($content, "\n")."\n{$key}={$value}\n";
            }
        }

        file_put_contents($path, $content);
    }

    public function render()
    {
        return view('livewire.settings');
    }
}
