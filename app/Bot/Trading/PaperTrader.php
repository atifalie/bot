<?php

namespace App\Bot\Trading;

use App\Models\BotState;
use Illuminate\Support\Facades\Log;

/**
 * Paper Trader — virtual trading with DB-backed state.
 * Port of bot/paper_trader.py — DB instead of JSON files.
 */
class PaperTrader
{
    protected const STATE_KEY = 'paper_trading';

    public function __construct(
        protected float $startingBalance = 10000.0,
    ) {
        $this->balance = $startingBalance;
        $this->openPositions = [];
        $this->loadState();
    }

    public function loadState(): void
    {
        $state = BotState::read(self::STATE_KEY);
        if ($state !== null) {
            $this->balance = $state['balance'] ?? $this->startingBalance;
            $this->openPositions = $state['open_positions'] ?? [];
        }
    }

    protected function saveState(): void
    {
        BotState::write(self::STATE_KEY, [
            'balance' => $this->balance,
            'open_positions' => $this->openPositions,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    protected float $balance;

    protected array $openPositions = [];

    public function buy(
        string $symbol,
        float $price,
        float $quantity,
        float $stopLoss,
        float $takeProfit,
    ): bool {
        $cost = $price * $quantity;

        if ($cost > $this->balance) {
            Log::warning('[PaperTrader] Insufficient virtual balance: '.number_format($this->balance, 2).' < '.number_format($cost, 2));

            return false;
        }

        $this->balance -= $cost;
        $this->openPositions[$symbol] = [
            'entry_price' => $price,
            'quantity' => $quantity,
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'direction' => 'BUY',
            'entry_time' => now()->toIso8601String(),
        ];
        $this->saveState();

        Log::info(sprintf(
            '[PaperTrader] BUY: %.6f %s @ %.4f (SL=%.4f TP=%.4f)',
            $quantity, $symbol, $price, $stopLoss, $takeProfit,
        ));

        return true;
    }

    public function sell(string $symbol, float $price, string $reason = ''): float
    {
        if (! isset($this->openPositions[$symbol])) {
            return 0.0;
        }

        $pos = $this->openPositions[$symbol];
        unset($this->openPositions[$symbol]);

        $entry = $pos['entry_price'];
        $qty = $pos['quantity'];
        $proceeds = $price * $qty;
        $this->balance += $proceeds;

        $pnlPct = (($price - $entry) / $entry) * 100;
        $won = $pnlPct > 0;

        $trade = [
            'type' => 'SELL',
            'symbol' => $symbol,
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => $qty,
            'pnl_percent' => $pnlPct,
            'won' => $won,
            'reason' => $reason,
            'entry_time' => $pos['entry_time'] ?? null,
            'exit_time' => now()->toIso8601String(),
        ];

        $this->recordTrade($trade);
        $this->saveState();

        $emoji = $won ? '✅' : '❌';
        Log::info(sprintf(
            '[PaperTrader] %s SELL: %s @ %.4f | PnL: %+.2f%% | Balance: %.2f',
            $emoji, $symbol, $price, $pnlPct, $this->balance,
        ));

        return $pnlPct;
    }

    public function checkExits(string $symbol, float $currentPrice): string
    {
        if (! isset($this->openPositions[$symbol])) {
            return '';
        }

        $pos = $this->openPositions[$symbol];
        if ($currentPrice <= $pos['stop_loss']) {
            return 'STOP_LOSS';
        }
        if ($currentPrice >= $pos['take_profit']) {
            return 'TAKE_PROFIT';
        }

        return '';
    }

    public function getSummary(): array
    {
        $trades = $this->getTrades();
        $sellTrades = array_filter($trades, fn ($t) => ($t['type'] ?? '') === 'SELL');
        $wins = array_filter($sellTrades, fn ($t) => ($t['won'] ?? false));
        $total = count($sellTrades);
        $winCount = count($wins);
        $totalPnl = array_sum(array_column($sellTrades, 'pnl_percent'));

        return [
            'balance' => $this->balance,
            'starting_balance' => $this->startingBalance,
            'total_return_pct' => (($this->balance - $this->startingBalance) / $this->startingBalance) * 100,
            'total_trades' => $total,
            'wins' => $winCount,
            'losses' => $total - $winCount,
            'win_rate' => $total > 0 ? ($winCount / $total) * 100 : 0,
            'total_pnl_pct' => $totalPnl,
            'open_positions' => count($this->openPositions),
        ];
    }

    public function printSummary(): void
    {
        $s = $this->getSummary();
        echo "\n".str_repeat('=', 50)."\n";
        echo "  PAPER TRADING SUMMARY\n";
        echo str_repeat('=', 50)."\n";
        echo '  Balance:     '.number_format($s['balance'], 2).' USDT (started: '.number_format($s['starting_balance'], 2).")\n";
        echo '  Return:      '.($s['total_return_pct'] >= 0 ? '+' : '').number_format($s['total_return_pct'], 2)."%\n";
        echo "  Trades:      {$s['total_trades']} ({$s['wins']}W / {$s['losses']}L)\n";
        echo '  Win Rate:    '.number_format($s['win_rate'], 1)."%\n";
        echo '  Total PnL:   '.($s['total_pnl_pct'] >= 0 ? '+' : '').number_format($s['total_pnl_pct'], 2)."%\n";
        echo "  Open:        {$s['open_positions']}\n";
        echo str_repeat('=', 50)."\n";
    }

    protected function recordTrade(array $trade): void
    {
        $key = 'paper_trades';
        $existing = BotState::read($key) ?? [];
        $existing[] = $trade;
        BotState::write($key, $existing);
    }

    protected function getTrades(): array
    {
        return BotState::read('paper_trades') ?? [];
    }
}
