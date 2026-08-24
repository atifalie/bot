<?php

namespace App\Console\Commands;

use App\Bot\Exchange\Trader;
use App\Models\BotState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Panic button — sell ALL non-stablecoin spot balances to USDT.
 * Port of close_all.py — uses Trader service.
 */
class BotCloseAll extends Command
{
    protected $signature = 'bot:close-all {--paper : Run in paper mode} {--balance= : Starting paper balance}';

    protected $description = 'Close ALL open positions (sell everything to USDT)';

    public function handle(Trader $trader): int
    {
        if ($this->option('paper')) {
            $balance = (float) ($this->option('balance') ?? 10000);

            return $this->closeAllPaper($balance);
        }

        $this->info('🚨 PANIC BUTTON: Closing ALL positions to USDT...');
        $this->info('⚠️  This will SELL every non-stablecoin spot balance.');

        if (! $this->confirm('Are you sure?')) {
            $this->error('Aborted.');

            return Command::FAILURE;
        }

        try {
            $trader->exchange->set_sandbox_mode(true);
            $this->info('Sandbox mode ON — safe test run.');

            $balance = $trader->getUsdtBalance();
            $this->info("Current USDT balance: {$balance}");

            $balances = $trader->exchange->fetch_balance();
            $skip = ['USDT', 'USDC', 'BUSD', 'TUSD', 'DAI', 'info', 'free', 'used', 'total', 'timestamp', 'datetime'];

            $coins = [];
            foreach ($balances as $coin => $info) {
                if (in_array($coin, $skip, true) || ! is_array($info)) {
                    continue;
                }
                $free = $info['free'] ?? 0;
                $free = is_numeric($free) ? (float) $free : 0.0;
                if ($free > 0.0001) {
                    $coins[$coin] = $free;
                }
            }

            if ($coins === []) {
                $this->info('No coins to sell — all USDT already.');

                return Command::SUCCESS;
            }

            $this->info('Found '.count($coins).' coins to sell...');

            $sold = 0;
            $failed = 0;
            $i = 0;

            foreach ($coins as $coin => $amt) {
                $i++;
                try {
                    $trader->placeMarketSell("{$coin}/USDT", $amt);
                    $sold++;
                    $this->line("  [{$i}/".count($coins)."] ✅ SOLD {$amt} {$coin}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  [{$i}/".count($coins)."] ❌ FAIL {$coin}: ".substr($e->getMessage(), 0, 60));
                }
            }

            sleep(2);
            $finalBalance = $trader->getUsdtBalance();
            $this->newLine();
            $this->info("Done! Sold: {$sold}, Failed: {$failed}, USDT: ".number_format($finalBalance, 2));

            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Close all failed: {$e->getMessage()}");
            Log::error("[BotCloseAll] {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    protected function closeAllPaper(float $balance): int
    {
        $this->info('📝 PAPER MODE — no real orders, just simulating...');
        // In paper mode, just reset the paper trader state
        BotState::write('paper_trading', [
            'balance' => $balance,
            'open_positions' => [],
            'updated_at' => now()->toIso8601String(),
        ]);
        BotState::write('paper_trades', []);

        $this->info("Paper state reset. Balance: {$balance}");

        return Command::SUCCESS;
    }
}
