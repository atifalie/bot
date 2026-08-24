<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $fillable = [
        'symbol', 'mode', 'direction', 'status', 'entry_price', 'exit_price', 'quantity',
        'stop_loss', 'take_profit', 'confidence', 'pnl_percent', 'pnl_usdt',
        'close_reason', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'entry_price' => 'float',
        'exit_price' => 'float',
        'quantity' => 'float',
        'stop_loss' => 'float',
        'take_profit' => 'float',
        'confidence' => 'float',
        'pnl_percent' => 'float',
        'pnl_usdt' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
