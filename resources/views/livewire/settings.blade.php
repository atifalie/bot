<div class="max-w-4xl mx-auto px-4 py-8">
    <header class="flex items-center justify-between mb-8">
        <div class="flex items-center gap-3">
            <span class="text-2xl">⚙️</span>
            <h1 class="text-xl font-semibold text-white">Bot Settings</h1>
        </div>
        <a href="/" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-800 text-slate-300 border border-slate-700 hover:bg-slate-700 hover:text-white transition-colors">
            ← Dashboard
        </a>
    </header>

    @if ($statusMessage)
        <div class="mb-6 p-3 rounded-lg text-sm font-medium {{ $statusOk ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-300' : 'bg-red-500/10 border border-red-500/30 text-red-300' }}">
            {{ $statusMessage }}
        </div>
    @endif

    {{-- ============ COINS ============ --}}
    <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-base font-semibold text-white">🪙 Coins</h2>
            <span class="text-xs text-slate-500">{{ count($symbols) }}/{{ \App\Livewire\Settings::MAX_SYMBOLS }}</span>
        </div>
        <p class="text-xs text-slate-500 mb-4">Comma se multiple coins add karo — e.g. <span class="font-mono text-slate-400">SOL, XRP/USDT, DOGE</span></p>

        <div class="flex flex-wrap gap-2 mb-5">
            @forelse ($symbols as $sym)
                <span class="inline-flex items-center gap-2 pl-3.5 pr-2 py-2 rounded-full bg-slate-800 border border-slate-700 font-mono text-sm font-semibold text-white">
                    {{ $sym }}
                    <button
                        wire:click="removeSymbol('{{ $sym }}')"
                        wire:confirm="Remove {{ $sym }} from watchlist?"
                        class="w-5 h-5 flex items-center justify-center rounded-full bg-slate-700 text-slate-400 hover:bg-red-500 hover:text-white transition-colors text-xs leading-none"
                    >×</button>
                </span>
            @empty
                <span class="text-slate-500 text-sm">Koi coin nahi — ek add karo.</span>
            @endforelse
        </div>

        <div class="flex gap-2 max-w-md">
            <input
                type="text"
                wire:model="newSymbol"
                placeholder="SOL, XRP/USDT, DOGE"
                class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3.5 py-2.5 text-sm font-mono text-white placeholder:text-slate-600 focus:outline-none focus:border-sky-500"
            >
            <button type="submit" class="px-4 py-2.5 rounded-lg text-sm font-semibold bg-sky-500/20 text-sky-400 border border-sky-500/40 hover:bg-sky-500 hover:text-white transition-colors">
                ➕ Add
            </button>
            <button
                type="button"
                wire:click="replaceAllSymbols"
                wire:confirm="Poori list REPLACE ho jayegi input wale coins se — theek hai?"
                class="px-4 py-2.5 rounded-lg text-sm font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/40 hover:bg-amber-500 hover:text-white transition-colors whitespace-nowrap"
                title="Purani list hata ke sirf input wale coins set karo"
            >🔄 Replace All</button>
        </div>
        <p class="text-xs text-slate-600 mt-2">💡 Ek saath poori nayi list chahiye? Coins likh ke <span class="text-amber-500">Replace All</span> dabao — ek click mein sab badal jayega.</p>
    </section>

    {{-- ============ TIMEFRAMES ============ --}}
    <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 mb-6">
        <h2 class="text-base font-semibold text-white mb-1">⏱️ Timeframe</h2>
        <p class="text-xs text-slate-500 mb-4">Candle interval — swing ke liye 15m recommended, scalping ke liye 3m–5m.</p>

        <div class="grid grid-cols-4 sm:grid-cols-8 gap-2 mb-6">
            @foreach (\App\Livewire\Settings::TIMEFRAMES as $tf)
                <button
                    wire:click="setTimeframe('{{ $tf }}')"
                    class="py-2.5 rounded-lg font-mono text-sm font-semibold transition-all {{ $timeframe === $tf ? 'bg-sky-500 text-white shadow-lg shadow-sky-500/25 scale-105' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:text-white hover:border-slate-500' }}"
                >{{ $tf }}</button>
            @endforeach
        </div>

        <label class="block text-xs uppercase tracking-wide text-slate-500 mb-2">Higher Timeframe (trend filter)</label>
        <div class="grid grid-cols-4 sm:grid-cols-8 gap-2">
            @foreach (\App\Livewire\Settings::TIMEFRAMES as $htf)
                <button
                    wire:click="setHigherTimeframe('{{ $htf }}')"
                    class="py-2 rounded-lg font-mono text-xs transition-all {{ $higherTimeframe === $htf ? 'bg-violet-500 text-white' : 'bg-slate-800/60 text-slate-500 border border-slate-800 hover:text-slate-200' }}"
                >{{ $htf }}</button>
            @endforeach
        </div>
    </section>

    {{-- ============ MIN CONFIDENCE ============ --}}
    <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-base font-semibold text-white">🎚️ Min Confidence</h2>
            <span class="font-mono text-lg font-bold {{ $minConfidence >= 70 ? 'text-emerald-400' : ($minConfidence >= 55 ? 'text-amber-400' : 'text-red-400') }}">{{ $minConfidence }}/100</span>
        </div>
        <p class="text-xs text-slate-500 mb-4">
            Bot sirf tab trade karega jab confidence is level se zyada ho.
            Zyada = safe/kam trades · Kam = zyada trades/risky.
            <span class="text-slate-400">(65 = balanced · 70+ = strict)</span>
        </p>
        <input
            type="range"
            min="0"
            max="95"
            step="5"
            wire:model.live="minConfidence"
            class="w-full accent-sky-500 cursor-pointer"
        >
        <div class="flex justify-between text-[10px] font-mono text-slate-600 mt-1">
            @foreach ([0, 25, 50, 75, 95] as $tick)
                <span>{{ $tick }}</span>
            @endforeach
        </div>
    </section>

    {{-- ============ SAVE ============ --}}
    <section class="flex items-center justify-between bg-slate-900/50 border border-slate-800 rounded-xl px-6 py-4">
        <p class="text-xs text-slate-500">Save karne ke baad bot ka <span class="font-mono text-slate-400">agla cycle</span> naye settings se chalega.</p>
        <button
            wire:click="save"
            wire:loading.attr="disabled"
            class="px-6 py-2.5 rounded-lg text-sm font-bold bg-emerald-500 text-slate-950 hover:bg-emerald-400 disabled:opacity-50 transition-colors shadow-lg shadow-emerald-500/20"
        >
            💾 Save Settings
        </button>
    </section>

    <footer class="mt-6 text-xs text-slate-600 text-center">
        Values .env mein save hoti hain (BOT_SYMBOLS, BOT_TIMEFRAME, BOT_HIGHER_TIMEFRAME)
    </footer>
</div>
