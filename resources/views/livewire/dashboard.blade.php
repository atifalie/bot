<div wire:poll.5s x-data="{ consoleOpen: false }">
    <div class="max-w-6xl mx-auto px-4 py-8">
        @if ($statusMessage)
            <div class="mb-4 p-3 rounded-lg text-sm font-medium {{ $statusOk ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-300' : 'bg-red-500/10 border border-red-500/30 text-red-300' }}">
                {{ $statusMessage }}
            </div>
        @endif

        @if (! $botRunning && ! $botStopping)
            <div class="mb-4 p-3 rounded-lg text-sm bg-slate-800/60 border border-slate-700 text-slate-300">
                ⏸ <strong class="text-white">Bot STOPPED</strong> — koi scan/trade nahi ho rahi. Open positions (agar hain) exchange pe waisi hain, unki monitoring band hai. Chalu karne ke liye ▶ Start Bot dabao.
            </div>
        @endif

        <header class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🤖</span>
                <h1 class="text-xl font-semibold text-white">Trading Bot
                    <span class="text-slate-500 font-normal">v5 · {{ strtoupper($exchange) }}</span>
                </h1>
                @if ($isDemo)
                    <span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/30">DEMO</span>
                @else
                    <span class="px-2 py-0.5 rounded text-xs font-semibold bg-red-500/15 text-red-400 border border-red-500/30">MAINNET</span>
                @endif
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 text-sm">
                    <span class="relative flex h-3 w-3">
                        @if ($botStopping)
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
                        @elseif ($botRunning && $healthy)
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                        @elseif ($botRunning)
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-400"></span>
                        @else
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-slate-600"></span>
                        @endif
                    </span>
                    <span class="font-medium {{ $botStopping ? 'text-amber-400' : ($botRunning ? ($healthy ? 'text-emerald-400' : 'text-amber-400') : 'text-slate-400') }}">
                        {{ $botStopping ? 'STOPPING…' : ($botRunning ? ($healthy ? 'RUNNING' : 'STARTING…') : 'STOPPED') }}
                    </span>
                    @if ($botRunning)
                        <span class="text-slate-500">· last cycle {{ $lastCycle }}</span>
                    @endif
                </div>

                <button
                    wire:click="scanNow"
                    x-on:click="$el.classList.add('opacity-50','pointer-events-none'); $el.textContent='⏳ Scanning…'"
                    class="px-4 py-2 rounded-lg text-sm font-semibold bg-sky-500/20 text-sky-300 border border-sky-400/40 hover:bg-sky-500 hover:text-white transition-colors">
                    ⚡ Scan Now
                </button>

                @if ($botStopping)
                    <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold bg-slate-800 text-slate-500 border border-slate-700 cursor-not-allowed">
                        ⏳ Stopping…
                    </button>
                @elseif ($botRunning)
                    <button
                        wire:click="stopBot"
                        wire:confirm="Bot STOP karein? Open positions ki SL/TP monitoring bhi band ho jayegi!"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg text-sm font-semibold bg-red-500/15 text-red-400 border border-red-500/30 hover:bg-red-500 hover:text-white transition-colors">
                        ⏹ Stop Bot
                    </button>
                @else
                    <button
                        wire:click="startBot"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500 hover:text-white transition-colors">
                        ▶ Start Bot
                    </button>
                @endif

                <a href="/settings" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-800 text-slate-300 border border-slate-700 hover:bg-slate-700 hover:text-white transition-colors">
                    ⚙️ Settings
                </a>
            </div>
        </header>

        {{-- ============ STATS (incl. today's trade summary) ============ --}}
        <section class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Balance (Demo)</p>
                <p class="text-2xl font-mono font-semibold text-white">
                    {{ $balance !== null ? number_format((float) $balance, 2) : '—' }}
                    <span class="text-sm text-slate-500">USDT</span>
                </p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Unrealized PnL</p>
                @if ($totalPnlUsdt != 0)
                    <p class="text-2xl font-mono font-semibold {{ $totalPnlUsdt >= 0 ? 'text-emerald-400' : 'text-red-400' }}">
                        {{ ($totalPnlUsdt >= 0 ? '+' : '').number_format((float) $totalPnlUsdt, 4) }}
                        <span class="text-sm text-slate-500">USDT</span>
                    </p>
                @else
                    <p class="text-2xl font-mono font-semibold text-white">—</p>
                @endif
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Open Positions</p>
                <p class="text-2xl font-mono font-semibold text-white">{{ $positions->count() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">API Errors</p>
                <p class="text-2xl font-mono font-semibold {{ $apiErrors > 0 ? 'text-red-400' : 'text-white' }}">{{ $apiErrors }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Today's Trades</p>
                <p class="text-2xl font-mono font-semibold text-white">{{ $dayStats['total'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Wins / Losses</p>
                <p class="text-2xl font-mono font-semibold">
                    <span class="text-emerald-400">{{ $dayStats['wins'] }}</span>
                    <span class="text-slate-600">/</span>
                    <span class="text-red-400">{{ $dayStats['losses'] }}</span>
                </p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 col-span-2">
                <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Day PnL ({{ \Carbon\Carbon::parse($filterDate)->format('d M') }})</p>
                <p class="text-2xl font-mono font-semibold {{ $dayStats['pnl'] > 0 ? 'text-emerald-400' : ($dayStats['pnl'] < 0 ? 'text-red-400' : 'text-white') }}">
                    {{ $dayStats['pnl'] > 0 ? '+' : '' }}{{ number_format((float) $dayStats['pnl'], 4) }}
                    <span class="text-sm text-slate-500">USDT</span>
                </p>
            </div>
        </section>

        {{-- ============ POSITIONS ============ --}}
        <section class="mb-8">
            <div class="flex items-center justify-between mb-3 gap-4 flex-wrap">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">📈 Open Positions — Live Tracking</h2>
                <button
                    wire:click="closeAllPositions"
                    wire:confirm="🚨 PANIC CLOSE: Sell ALL positions at market price?"
                    @if ($positions->isEmpty()) disabled @endif
                    class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-red-500/15 text-red-400 border border-red-500/40 hover:bg-red-500 hover:text-white hover:border-red-500 transition-all {{ $positions->isEmpty() ? 'opacity-40 cursor-not-allowed hover:bg-red-500/15 hover:text-red-400 hover:border-red-500/40' : 'animate-pulse-soft' }}"
                >
                    🚨 Close All
                </button>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                @if ($positions->isEmpty())
                    <p class="text-slate-500 text-sm p-6 text-center">No open positions — bot is scanning for entries.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-3">Symbol</th>
                                <th class="px-4 py-3">Side</th>
                                <th class="px-4 py-3">Entry</th>
                                <th class="px-4 py-3">Last Price</th>
                                <th class="px-4 py-3">PnL</th>
                                <th class="px-4 py-3">SL / TP</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            @foreach ($positions as $pos)
                                @php
                                    $entry = (float) ($pos['entry_price'] ?? 0);
                                    $sl = (float) ($pos['stop_loss'] ?? 0);
                                    $tp = (float) ($pos['take_profit'] ?? 0);
                                    $last = $pos['last_price'];
                                    $pnlPct = $pos['pnl_pct'];
                                    $pnlUsdt = $pos['pnl_usdt'];
                                @endphp
                                <tr class="border-b border-slate-800/50 last:border-0 hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-semibold text-white">{{ $pos['symbol'] }}</td>
                                    <td class="px-4 py-3"><span class="text-emerald-400">{{ $pos['direction'] ?? 'BUY' }}</span></td>
                                    <td class="px-4 py-3">{{ \App\Bot\Support\PriceFormatter::format($entry) }}</td>
                                    <td class="px-4 py-3 font-bold text-white">
                                        @if ($last)
                                            {{ \App\Bot\Support\PriceFormatter::format($last) }}
                                        @else
                                            <span class="text-slate-600 animate-pulse">…</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($pnlPct !== null)
                                            <span class="{{ $pnlPct >= 0 ? 'text-emerald-400' : 'text-red-400' }} font-semibold">
                                                {{ ($pnlPct >= 0 ? '+' : '').number_format((float) $pnlPct, 3) }}%
                                            </span>
                                            <span class="block text-xs {{ $pnlUsdt >= 0 ? 'text-emerald-500/70' : 'text-red-500/70' }}">
                                                {{ ($pnlUsdt >= 0 ? '+' : '').number_format((float) $pnlUsdt, 4) }} ₮
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-red-400">{{ \App\Bot\Support\PriceFormatter::format($sl) }}</span>
                                        <span class="text-slate-600"> / </span>
                                        <span class="text-emerald-400">{{ \App\Bot\Support\PriceFormatter::format($tp) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            wire:click="closePosition('{{ $pos['symbol'] }}')"
                                            wire:confirm="Close {{ $pos['symbol'] }} position at market price?"
                                            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-500/15 text-red-400 border border-red-500/30 hover:bg-red-500 hover:text-white transition-colors"
                                        >
                                            ✕ Close
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

        {{-- ============ TRADES HISTORY ============ --}}
        <section class="mb-8">
            <div class="flex items-center justify-between mb-3 gap-4 flex-wrap">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">📚 Trades History</h2>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-xs text-slate-400">
                        📅 Date:
                        <input
                            type="date"
                            wire:model.live="filterDate"
                            value="{{ $filterDate }}"
                            class="bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 [color-scheme:dark] focus:outline-none focus:border-sky-500"
                        >
                    </label>
                    <button wire:click="$set('filterDate', '{{ today()->toDateString() }}')"
                        class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $filterDate === today()->toDateString() ? 'bg-sky-500/20 text-sky-400 border border-sky-500/40' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:text-slate-200' }} transition-colors">
                        Today
                    </button>
                    <button wire:click="$set('filterDate', '{{ today()->subDay()->toDateString() }}')"
                        class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $filterDate === today()->subDay()->toDateString() ? 'bg-sky-500/20 text-sky-400 border border-sky-500/40' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:text-slate-200' }} transition-colors">
                        Yesterday
                    </button>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                @if ($filteredTrades->isEmpty())
                    <p class="text-slate-500 text-sm p-6 text-center">No closed trades on {{ \Carbon\Carbon::parse($filterDate)->format('d M Y') }}.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-3">Closed At</th>
                                <th class="px-4 py-3">Symbol</th>
                                <th class="px-4 py-3">Entry → Exit</th>
                                <th class="px-4 py-3">Qty</th>
                                <th class="px-4 py-3">PnL</th>
                                <th class="px-4 py-3">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            @foreach ($filteredTrades as $t)
                                <tr class="border-b border-slate-800/50 last:border-0 hover:bg-slate-800/30">
                                    <td class="px-4 py-3 text-slate-400">{{ $t->closed_at?->format('H:i:s') }}</td>
                                    <td class="px-4 py-3 font-semibold text-white">{{ $t->symbol }}</td>
                                    <td class="px-4 py-3 text-slate-300">
                                        {{ \App\Bot\Support\PriceFormatter::format($t->entry_price) }} → {{ \App\Bot\Support\PriceFormatter::format($t->exit_price) }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-400">{{ $t->quantity }}</td>
                                    <td class="px-4 py-3">
                                        <span class="{{ (float) $t->pnl_percent >= 0 ? 'text-emerald-400' : 'text-red-400' }} font-semibold">
                                            {{ ((float) $t->pnl_percent >= 0 ? '+' : '').number_format((float) $t->pnl_percent, 2) }}%
                                        </span>
                                        <span class="block text-xs {{ (float) $t->pnl_usdt >= 0 ? 'text-emerald-500/70' : 'text-red-500/70' }}">
                                            {{ ((float) $t->pnl_usdt >= 0 ? '+' : '').number_format((float) $t->pnl_usdt, 4) }} ₮
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 max-w-[12rem] truncate" title="{{ $t->close_reason }}">{{ $t->close_reason ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

        <footer class="flex items-center justify-between text-xs text-slate-600">
            <span>Health: {{ $hbReason }} · prices cached 20s · auto-refresh 5s · cycles {{ number_format((float) $cycleCount) }}</span>
            <span>Active symbols: {{ implode(', ', $activeSymbols) ?: '—' }}</span>
        </footer>
    </div>

    {{-- ============ STICKY CONSOLE BUTTON ============ --}}
    <button
        @click="consoleOpen = true"
        class="fixed bottom-6 right-6 z-40 flex items-center gap-2 pl-4 pr-5 py-3 rounded-full bg-[#161b22] text-emerald-400 border border-emerald-500/30 shadow-2xl shadow-black/60 hover:border-emerald-400 hover:scale-105 transition-all font-mono text-sm font-semibold"
    >
        <span class="relative flex h-2.5 w-2.5">
            @if ($healthy)
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60"></span>
            @endif
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 {{ $healthy ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
        </span>
        &gt;_ Console
    </button>

    {{-- ============ CONSOLE OVERLAY ============ --}}
    <div
        x-show="consoleOpen"
        x-cloak
        x-transition.opacity.duration.200ms
        class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-end sm:items-center justify-center sm:p-6"
        @click.self="consoleOpen = false"
        @keydown.escape.window="consoleOpen = false"
    >
        <div
            x-show="consoleOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-8"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="w-full max-w-4xl rounded-t-2xl sm:rounded-2xl overflow-hidden border border-slate-700 shadow-2xl bg-[#0d1117]"
        >
            <div class="flex items-center gap-2 px-4 py-2.5 bg-[#161b22] border-b border-slate-700/70">
                <span class="h-3 w-3 rounded-full bg-[#ff5f57]" @click="consoleOpen = false" title="Close"></span>
                <span class="h-3 w-3 rounded-full bg-[#febc2e]"></span>
                <span class="h-3 w-3 rounded-full bg-[#28c840]" @click="consoleOpen = false" title="Close"></span>
                <span class="ml-3 text-xs font-mono text-slate-400">bot@{{ strtolower($exchange) }}-demo — live activity</span>
                <span class="ml-auto flex items-center gap-1.5 text-[10px] font-mono text-emerald-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span> LIVE · auto-updates
                </span>
                <button @click="consoleOpen = false" class="ml-3 text-slate-500 hover:text-white transition-colors leading-none text-lg">×</button>
            </div>
            <div class="px-4 py-3 font-mono text-[13px] leading-7 overflow-y-auto h-[55vh] sm:h-[28rem]">
                @forelse ($cycle as $r)
                    @php
                        $isBuy = str_contains($r['action'], 'BUY');
                        $isExit = str_contains($r['action'], 'EXIT');
                        $isWait = str_contains($r['action'], 'WAIT');
                        $color = match (true) {
                            $isBuy => 'text-emerald-400',
                            $isExit => 'text-orange-400',
                            $isWait => 'text-slate-600',
                            default => 'text-sky-400', // HOLD
                        };
                        $confColor = ($r['confidence'] ?? 0) >= 65 ? 'text-emerald-300' : (($r['confidence'] ?? 0) >= 55 ? 'text-amber-300' : 'text-slate-600');
                    @endphp
                    <p class="whitespace-nowrap">
                        <span class="text-slate-700 select-none">{{ $r['time'] }}</span>
                        <span class="inline-block w-[110px] truncate align-bottom font-semibold {{ $color }}">{{ $r['symbol'] }}</span>
                        <span class="inline-block w-10 text-right {{ $confColor }}">{{ $r['confidence'] ?? '—' }}</span>
                        <span class="{{ $color }} opacity-80 ml-2">{{ $r['reason'] }}</span>
                    </p>
                @empty
                    <p class="text-slate-600">current cycle ka wait hai…</p>
                @endforelse
            </div>
            <div class="px-4 py-2 bg-[#161b22] border-t border-slate-700/70 text-[10px] font-mono text-slate-600 flex justify-between">
                <span>{{ count($cycle) }} coins is cycle mein</span>
                <span>ESC ya × dabao band karne ke liye</span>
            </div>
        </div>
    </div>
</div>
