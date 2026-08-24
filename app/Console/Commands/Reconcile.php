<?php

namespace App\Console\Commands;

use App\Bot\Exchange\Trader;
use App\Models\BotState;
use App\Models\Trade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Exchange ↔ local-state reconciliation.
 *
 * Finds:
 *  - ORPHAN bags: assets on exchange with NO local open position
 *    (caused by the test-suite DB wipe bug / crash after order fill)
 *  - GHOST states: local in_position=true but zero balance on exchange
 *
 * --sell-orphans market-sells orphan bags back to USDT and records them
 * as CLOSED trades (reason: reconcile_orphan_sell) for audit trail.
 */
class Reconcile extends Command
{
    protected $signature = 'bot:reconcile
        {--sell-orphans : Market-sell orphan bags (min 1 USDT value) back to USDT}
        {--clear-ghosts : Remove local states whose exchange balance is zero}';

    protected $description = 'Compare exchange balances vs local position state; fix drift';

    public function handle(Trader $trader): int
    {
        $assets = $trader->getAssetBalances();
        $localOpen = BotState::query()
            ->where('key', 'like', 'position_state_%')
            ->get()
            ->filter(fn ($s) => ($s->value['in_position'] ?? false) === true)
            ->mapWithKeys(fn ($s) => [
                // position_state_BTC_USDT → BTC
                str_replace(['position_state_', '_USDT'], '', $s->key) => $s,
            ]);

        $this->info('── EXCHANGE ASSETS ──');
        $orphans = [];
        foreach ($assets as $asset => $qty) {
            $tracked = $localOpen->has($asset);
            $price = 0.0;
            try {
                $price = $tracked ? 0.0 : $trader->getCurrentPrice($asset.'/USDT');
            } catch (\Throwable) {
                // not tradable against USDT — report only
            }
            $value = $price * $qty;
            $tag = $tracked ? '<fg=green>tracked</>' : ($value > 0 ? '<fg=yellow>ORPHAN</>' : '<fg=gray>no-price</>');
            $this->line(sprintf(' %-10s %-.8f  ≈%8.2f USD  [%s]', $asset, $qty, $value, $tag));
            if (! $tracked && $value > 0) {
                $orphans[$asset] = ['qty' => $qty, 'value' => $value];
            }
        }

        $this->newLine();
        $this->info('── LOCAL STATES ──');
        $ghosts = [];
        foreach ($localOpen as $asset => $state) {
            $exchangeQty = $assets[$asset] ?? 0;
            if ($exchangeQty <= 0) {
                $ghosts[] = $asset;
                $this->line(sprintf(' %-10s <fg=red>GHOST</> — local says open, exchange has 0', $asset));
            } else {
                $this->line(sprintf(' %-10s tracked, qty %.8f', $asset, $exchangeQty));
            }
        }
        if ($localOpen->isEmpty()) {
            $this->line(' (none open)');
        }

        $this->newLine();
        if ($orphans === [] && $ghosts === []) {
            $this->info('✅ Fully reconciled — no drift.');

            return Command::SUCCESS;
        }

        if ($this->option('clear-ghosts') && $ghosts !== []) {
            foreach ($ghosts as $asset) {
                $state = $localOpen[$asset];
                $state->delete();
                $this->warn(" 🧹 ghost state cleared: {$asset}");
            }
        }

        if ($this->option('sell-orphans') && $orphans !== []) {
            $soldUsd = 0.0;
            foreach ($orphans as $asset => ['qty' => $qty, 'value' => $value]) {
                if ($value < 1.0) {
                    $this->line(" ⏭️ {$asset} dust (<1 USD) — skip");

                    continue;
                }
                try {
                    $symbol = $asset.'/USDT';
                    $trader->placeMarketSell($symbol, $qty);
                    $exitPrice = $trader->getCurrentPrice($symbol);
                    Trade::create([
                        'symbol' => $symbol,
                        'mode' => 'LIVE',
                        'direction' => 'BUY',
                        'status' => 'CLOSED',
                        'entry_price' => 0,
                        'exit_price' => $exitPrice,
                        'quantity' => $qty,
                        'pnl_usdt' => 0,
                        'pnl_percent' => 0,
                        'close_reason' => 'reconcile_orphan_sell',
                        'opened_at' => now(),
                        'closed_at' => now(),
                    ]);
                    $soldUsd += $value;
                    $this->info(" 💸 sold {$asset}: {$qty} ≈ {$value} USD");
                    usleep(400_000);
                } catch (\Throwable $e) {
                    $this->error(" ✗ {$asset} sell failed: {$e->getMessage()}");
                }
            }
            Log::warning('[Reconcile] sold '.count($orphans)." orphan bags ≈ {$soldUsd} USD");
            $this->newLine();
            $this->info("✅ Reconciled — {$soldUsd} USD returned to USDT.");
        } elseif ($orphans !== []) {
            $this->warn(' ⚠️ '.count($orphans).' orphan bag(s). Sell karne ke liye: php artisan bot:reconcile --sell-orphans');
        }

        return Command::SUCCESS;
    }
}
