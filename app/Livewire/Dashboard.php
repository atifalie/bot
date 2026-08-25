<?php

namespace App\Livewire;

use App\Bot\Exchange\Trader;
use App\Bot\Monitoring\Heartbeat;
use App\Models\BotState;
use App\Models\Trade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Livewire\Component;

class Dashboard extends Component
{
    public string $filterDate = '';

    public ?string $statusMessage = null;

    public ?bool $statusOk = true;

    public bool $botRunning = false;

    public bool $botStopping = false;

    public function mount(): void
    {
        $this->filterDate = today()->toDateString();
        $this->refreshBotState();
    }

    protected function refreshBotState(): void
    {
        $stopping = is_file(storage_path('app/BOT_STOP'));
        $running = self::detectDaemonProcess();

        // Flag laga hai magar daemon abhi zinda = graceful exit pending
        $this->botRunning = $running && ! $stopping;
        $this->botStopping = $stopping && $running;
    }

    protected static function detectDaemonProcess(): bool
    {
        $out = @shell_exec('pgrep -f "artisan bot:daemon" 2>/dev/null');
        $pids = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));

        return $pids !== [];
    }

    public function render()
    {
        // Har poll (5s) pe process-list se fresh state — source of truth
        $this->refreshBotState();

        $hb = Heartbeat::check();
        $hbDetails = $hb['details'] ?? [];

        // Balance + prices daemon ke Cache se padho — ZERO Bybit API calls
        // (render() ab kabhi block nahi hoga, 504 khatam)
        $balance = Cache::get('dash_usdt_balance');

        // DEMO-only positions (tracked via position_state_* keys)
        $positions = BotState::query()
            ->where('key', 'like', 'position_state_%')
            ->get()
            ->filter(fn ($s) => ($s->value['in_position'] ?? false) === true)
            ->map(fn ($s) => [
                'symbol' => str_replace('_', '/', substr($s->key, strlen('position_state_'))),
                ...($s->value ?? []),
            ])
            ->values()
            ->map(function (array $pos) {
                $pos['last_price'] = null;
                $pos['pnl_pct'] = null;
                $pos['pnl_usdt'] = null;

                if (! isset($pos['entry_price'])) {
                    return $pos;
                }

                // Daemon ne cache mein rakha hai — read only, no API call
                $last = Cache::get('dash_price_'.str_replace('/', '_', $pos['symbol']));

                if ($last === null) {
                    return $pos;
                }

                $entry = (float) $pos['entry_price'];
                $qty = (float) ($pos['quantity'] ?? 0);

                $pos['last_price'] = $last;
                $pos['pnl_pct'] = $entry > 0 ? (($last - $entry) / $entry) * 100 : null;
                $pos['pnl_usdt'] = ($last - $entry) * $qty;

                return $pos;
            });

        $date = Carbon::parse($this->filterDate ?: today());

        $filtered = Trade::query()
            ->where('status', 'CLOSED')
            ->where('mode', 'LIVE')
            ->whereDate('closed_at', $date)
            ->orderByDesc('closed_at')
            ->limit(50)
            ->get();

        return view('livewire.dashboard', [
            'healthy' => $hb['healthy'],
            'hbReason' => $hb['reason'],
            'cycleCount' => $hbDetails['cycle_count'] ?? 0,
            'lastCycle' => isset($hbDetails['timestamp'])
                ? Carbon::parse($hbDetails['timestamp'])->diffForHumans()
                : '-',
            'activeSymbols' => $hbDetails['active_symbols'] ?? [],
            'apiErrors' => $hbDetails['api_errors'] ?? 0,
            'isDemo' => config('bot.exchange.use_demo'),
            'exchange' => env('EXCHANGE', 'bybit'),
            'balance' => $balance,
            'positions' => $positions,
            'totalPnlUsdt' => $positions->sum(fn ($p) => $p['pnl_usdt'] ?? 0),
            'filteredTrades' => $filtered,
            'dayStats' => [
                'total' => $filtered->count(),
                'wins' => $filtered->where('pnl_percent', '>', 0)->count(),
                'losses' => $filtered->where('pnl_percent', '<=', 0)->count(),
                'pnl' => round((float) $filtered->sum('pnl_usdt'), 4),
            ],
            'cycle' => $this->currentCycleRows(),
        ]);
    }

    public function startBot(): void
    {
        $this->statusMessage = null;

        @unlink(storage_path('app/BOT_STOP'));

        if (! self::detectDaemonProcess()) {
            // Detached wrapper — FPM request khatam hone ke baad bhi zinda rehta hai
            exec('nohup sh '.escapeshellarg(base_path().'/bot-wrapper.sh').' >> /tmp/wrapper.log 2>&1 &');
            sleep(3);
        }

        $this->refreshBotState();
        $this->statusOk = true;
        $this->statusMessage = $this->botRunning
            ? '▶️ Bot started — hourly scans resume (pehla scan agle candle close pe hoga).'
            : '⏳ Start signal de diya — 5-10 sec mein processes up ho jayengi (page refresh karke dekho).';
    }

    public function stopBot(): void
    {
        $this->statusMessage = null;

        touch(storage_path('app/BOT_STOP'));

        $this->botStopping = true;
        $this->botRunning = false;
        $this->statusOk = true;
        $this->statusMessage = '🛑 STOP signal — daemon ~1 min ke andar gracefully band ho jayega. ⚠️ Open positions ka SL/TP monitoring bhi band! Exchange pe wo positions waisi hi rahengi.';
    }

    public function scanNow(): void
    {
        $this->statusMessage = null;

        try {
            $signalPath = storage_path('app/scan_now.signal');

            // Daemon ko signal bhejo — wo live WS data se foran scan karega
            if ($this->botRunning && ! $this->botStopping) {
                file_put_contents($signalPath, (string) time());
                $this->statusOk = true;
                $this->statusMessage = '⚡ Signal sent — daemon 1-2 sec mein saare coins scan karega (live WebSocket data). Neeche console mein fresh decisions dekho.';
            } else {
                // Daemon off hai — fallback: bot:run (REST) se manually scan
                \Artisan::call('bot:run');
                $this->statusOk = true;
                $this->statusMessage = '⚡ Manual scan complete (REST fallback, daemon off) — console mein decisions dekho.';
            }

            Cache::forget('dash_usdt_balance');
            foreach (Config::get('bot.market.symbols', []) as $s) {
                Cache::forget('dash_price_'.str_replace('/', '_', $s));
            }
        } catch (\Throwable $e) {
            $this->statusOk = false;
            $this->statusMessage = '❌ Scan failed: '.str($e->getMessage())->limit(80);
        }
    }

    public function closePosition(string $symbol): void
    {
        $this->statusMessage = null;

        try {
            $this->closeLive($symbol);
        } catch (\Throwable $e) {
            $this->statusOk = false;
            $this->statusMessage = "❌ {$symbol} close failed: ".str($e->getMessage())->limit(80);

            return;
        }

        Cache::forget('dash_usdt_balance');
        Cache::forget('dash_price_'.str_replace('/', '_', $symbol));

        $this->statusOk = true;
        $this->statusMessage = "✅ {$symbol} closed at market — position cleared.";
    }

    public function closeAllPositions(): void
    {
        $this->statusMessage = null;

        $liveKeys = BotState::query()
            ->where('key', 'like', 'position_state_%')
            ->get()
            ->filter(fn ($s) => ($s->value['in_position'] ?? false) === true);

        if ($liveKeys->isEmpty()) {
            $this->statusOk = true;
            $this->statusMessage = 'No open positions to close.';

            return;
        }

        $closed = 0;
        $failed = 0;
        $errors = [];

        foreach ($liveKeys as $s) {
            $symbol = str_replace('_', '/', substr($s->key, strlen('position_state_')));

            try {
                $this->closeLive($symbol);
                Cache::forget('dash_price_'.str_replace('/', '_', $symbol));
                $closed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$symbol}: ".str($e->getMessage())->limit(40);
            }
        }

        Cache::forget('dash_usdt_balance');

        $this->statusOk = $failed === 0;
        $this->statusMessage = match (true) {
            $failed > 0 => "⚠️ Closed {$closed}, failed {$failed} — ".implode('; ', $errors),
            default => "🚨 PANIC CLOSE done — {$closed} position(s) closed at market.",
        };
    }

    protected function recordClosedTrade(string $symbol, array $pos, float $exitPrice, string $reason): void
    {
        $entry = (float) ($pos['entry_price'] ?? 0);
        $qty = (float) ($pos['quantity'] ?? 0);

        Trade::create([
            'symbol' => $symbol,
            'mode' => 'LIVE',
            'direction' => 'BUY',
            'status' => 'CLOSED',
            'entry_price' => $entry,
            'exit_price' => $exitPrice,
            'quantity' => $qty,
            'stop_loss' => $pos['stop_loss'] ?? null,
            'take_profit' => $pos['take_profit'] ?? null,
            'confidence' => $pos['confidence'] ?? null,
            'pnl_percent' => $entry > 0 ? round((($exitPrice - $entry) / $entry) * 100, 4) : null,
            'pnl_usdt' => round(($exitPrice - $entry) * $qty, 8),
            'close_reason' => "manual: dashboard ({$reason})",
            'opened_at' => $pos['entry_time'] ?? now(),
            'closed_at' => now(),
        ]);
    }

    protected function closeLive(string $symbol): void
    {
        $stateKey = 'position_state_'.str_replace('/', '_', $symbol);
        $pos = BotState::read($stateKey);

        if (! $pos || empty($pos['in_position'])) {
            throw new \RuntimeException('no tracked position');
        }

        $trader = app(Trader::class);
        $trader->ensureMarkets();

        try {
            $trader->cancelOpenOrders($symbol);
        } catch (\Throwable) {
        }

        $exitPrice = $trader->getCurrentPrice($symbol);
        $result = $trader->sellBasePosition($symbol, (float) $pos['quantity']);
        BotState::remove($stateKey);

        $fillPrice = $result['order']['average'] ?? $result['order']['price'] ?? $exitPrice;

        $this->recordClosedTrade($symbol, $pos, (float) $fillPrice, 'market sell');

        \Log::info('[Dashboard] Manual close '.$symbol.' — sold '.var_export($result['sold_qty'], true));
    }

    /**
     * Parse the Laravel log into clean, human-readable console lines.
     * Multi-line messages are joined; oldest first within the window.
     */
    /**
     * Current cycle ka compact view: har coin ek line — symbol/confidence/reason.
     */
    protected function currentCycleRows(): array
    {
        // Aaj ke saare decisions se aakhri 20 dikhata hai — restart-marker
        // anchoring hatayi (har daemon-restart pe console blank ho jata tha).
        $path = storage_path('logs/bot_decisions-'.now()->toDateString().'.log');
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        $pending = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): ?(.*)$/', (string) $line, $m)) {
                continue;
            }
            [, $ts, $level, $msg] = $m;

            // JSON payload → symbol/confidence nikaalo, line khud drop karo
            if (str_starts_with($msg, '{"symbol"')) {
                $decoded = json_decode($msg, true);
                $pending = is_array($decoded) ? [
                    'symbol' => $decoded['symbol'] ?? '?',
                    'confidence' => isset($decoded['confidence']) ? round((float) $decoded['confidence'], 1) : null,
                ] : null;

                continue;
            }

            if (! str_starts_with($msg, 'DECISION')) {
                continue;
            }

            $symbol = $pending['symbol'] ?? '?';
            $confidence = $pending['confidence'] ?? null;
            $pending = null;

            $after = preg_replace('/^DECISION(\s*\[[^\]]+\])?:\s*/', '', $msg);
            $action = trim(explode('|', (string) $after)[0]);
            $reason = str_contains($after, 'reason:')
                ? substr($after, (int) strpos($after, 'reason:') + 7)
                : $after;
            $reason = str_replace(['Validation FAILED: ', 'circuit_breaker: '], '', trim((string) $reason));
            $shortReason = mb_substr(explode(',', $reason)[0], 0, 46);

            $rows[] = [
                'time' => substr($ts, 11),
                'symbol' => $symbol,
                'action' => $action,
                'confidence' => $confidence,
                'reason' => $shortReason,
            ];
        }

        // Aakhri 20 (naye pehle)
        return array_reverse(array_slice($rows, -20));
    }
}
