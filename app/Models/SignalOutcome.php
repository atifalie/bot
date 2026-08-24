<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalOutcome extends Model
{
    protected $fillable = [
        'signal_id', 'symbol', 'price_at_signal',
        'max_gain_15m', 'max_drawdown_15m',
        'max_gain_30m', 'max_drawdown_30m',
        'max_gain_1h', 'max_drawdown_1h',
        'max_gain_4h', 'max_drawdown_4h',
        'max_gain_24h', 'max_drawdown_24h',
        'reached_5pct', 'reached_10pct', 'reached_minus_5pct',
        'tracked_at',
    ];

    protected $casts = [
        'price_at_signal' => 'float',
        'max_gain_15m' => 'float',
        'max_drawdown_15m' => 'float',
        'max_gain_30m' => 'float',
        'max_drawdown_30m' => 'float',
        'max_gain_1h' => 'float',
        'max_drawdown_1h' => 'float',
        'max_gain_4h' => 'float',
        'max_drawdown_4h' => 'float',
        'max_gain_24h' => 'float',
        'max_drawdown_24h' => 'float',
        'reached_5pct' => 'boolean',
        'reached_10pct' => 'boolean',
        'reached_minus_5pct' => 'boolean',
        'tracked_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
