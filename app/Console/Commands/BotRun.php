<?php

namespace App\Console\Commands;

use App\Bot\Exchange\Trader;
use App\Bot\MarketData\CandleFetcher;
use App\Bot\Monitoring\Heartbeat;
use App\Bot\Risk\PortfolioCap;
use App\Bot\Trading\PaperTrader;
use App\Bot\Trading\StateStore;
use App\Bot\Trading\SymbolCycleProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Main Bot Runner — the trading loop (port of bot.py main()).
 * Runs one cycle per symbol: fetch → validate → features → regime → exit check →
 * HTF → scoring → Phase 2 gates → validation → risk → BUY/HOLD/EXIT.
 */
class BotRun extends Command
{
    protected $signature = 'bot:run
        {--paper : Run in paper trading mode}
        {--balance= : Starting paper balance (default 10000)}
        {--symbol= : Override symbols (comma-separated)}
        {--single : Run single cycle and exit}';

    protected $description = 'Run one trading cycle (or loop with --single=false via scheduler)';

    public function handle(
        Trader $trader,
        CandleFetcher $candleFetcher,
        SymbolCycleProcessor $processor,
    ): int {
        $paperMode = $this->option('paper');
        $paperBalance = (float) ($this->option('balance') ?? 10000);
        $symbolsOverride = $this->option('symbol');
        $singleCycle = $this->option('single');

        $this->info(sprintf(
            '🤖 Bot v5 starting (profile=%s) %s',
            Config::get('bot.profile'),
            $paperMode ? '[PAPER]' : '[LIVE]',
        ));

        // Initialize paper trader if needed
        $paperTrader = null;
        if ($paperMode) {
            $paperTrader = new PaperTrader($paperBalance);
            $this->info("Paper mode: starting balance = {$paperBalance} USDT");
        }

        // Determine active symbols
        $symbols = $symbolsOverride
            ? array_map('trim', explode(',', $symbolsOverride))
            : Config::get('bot.market.symbols', ['BTC/USDT', 'ETH/USDT']);

        // Exchange pe jo listed nahi wo pehle hi nikaal do — cycle bachao
        $before = count($symbols);
        $symbols = $trader->validateSymbols($symbols);
        if (count($symbols) < $before) {
            $this->warn('⚠️ '.($before - count($symbols)).' invalid symbol(s) skipped — baaki '.count($symbols).' active');
        }

        $this->info('Active symbols: '.implode(', ', $symbols));

        // Load/reconcile state for each symbol
        foreach ($symbols as $symbol) {
            $state = app(StateStore::class)->loadPosition($symbol);
            $this->line("  {$symbol}: ".($state['in_position'] ? "IN POSITION @ {$state['entry_price']}" : 'flat'));
        }

        // Heartbeat startup
        Heartbeat::logStartup();
        $cycleCount = 0;
        $lastScanTime = 0;

        // Main loop
        while (true) {
            $cycleCount++;
            $cycleStart = microtime(true);

            try {
                // Update heartbeat
                $usdtBalance = $paperMode ? $paperTrader->getSummary()['balance'] : $trader->getUsdtBalance();
                Heartbeat::update(
                    cycleCount: $cycleCount,
                    activeSymbols: $symbols,
                    openPositions: array_keys(array_filter(
                        array_map(fn ($s) => app(StateStore::class)->loadPosition($s)['in_position'] ? $s : null, $symbols)
                    )),
                    balance: $usdtBalance,
                    lastScanAgeSeconds: $lastScanTime > 0 ? time() - $lastScanTime : 0,
                    status: 'running',
                );

                // Periodic scanner (every 5 min)
                $scanInterval = Config::get('bot.scanner.interval_minutes', 5) * 60;
                if ($lastScanTime === 0 || (time() - $lastScanTime) >= $scanInterval) {
                    $this->info('🔍 Running scanner...');
                    // TODO: Scanner integration (Phase 11)
                    $lastScanTime = time();
                    $this->info('  Scanner complete (stub)');
                }

                // Per-symbol cycle — one bad symbol must NOT kill the rest.
                // Cap: max_open_trades bhari to flat coins ki search skip.
                $stateStore = app(StateStore::class);
                foreach ($symbols as $symbol) {
                    try {
                        $processor->process(
                            symbol: $symbol,
                            candleFetcher: $candleFetcher,
                            paperMode: $paperMode,
                            paperTrader: $paperTrader,
                            usdtBalance: $usdtBalance,
                            canOpenNew: PortfolioCap::canOpenNew($stateStore, $symbols),
                        );
                    } catch (\Throwable $e) {
                        $this->error("[{$symbol}] symbol skipped: {$e->getMessage()}");
                        Log::error("[BotRun] [{$symbol}] skipped: {$e->getMessage()}");
                    }
                }

            } catch (\Throwable $e) {
                $this->error("Cycle error: {$e->getMessage()}");
                Log::error("[BotRun] Cycle error: {$e->getMessage()}");
            }

            if ($singleCycle) {
                $this->info('Single cycle complete — exiting.');
                Heartbeat::logShutdown('single_cycle');

                return Command::SUCCESS;
            }

            // Sleep until next cycle
            $interval = Config::get('bot.loop.check_interval_seconds', 60);
            $elapsed = microtime(true) - $cycleStart;
            $sleep = max(1, $interval - $elapsed);

            if ($sleep > 0) {
                sleep((int) $sleep);
            }
        }
    }
}
