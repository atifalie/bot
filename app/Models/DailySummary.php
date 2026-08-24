<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySummary extends Model
{
    protected $table = 'daily_summaries';

    protected $fillable = [
        'trade_date', 'total_trades', 'wins', 'losses',
        'total_pnl_percent', 'total_pnl_usdt', 'win_rate',
        'best_trade_pnl', 'worst_trade_pnl', 'closing_balance',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'total_trades' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'total_pnl_percent' => 'float',
        'total_pnl_usdt' => 'float',
        'win_rate' => 'float',
        'best_trade_pnl' => 'float',
        'worst_trade_pnl' => 'float',
        'closing_balance' => 'float',
    ];
}
