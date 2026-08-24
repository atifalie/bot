<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Signal extends Model
{
    protected $fillable = [
        'signaled_at', 'symbol', 'timeframe',
        'discovery_score', 'volume_acceleration', 'price_acceleration',
        'relative_volume', 'trade_count', 'quote_volume', 'price_change_24h',
        'bid_ask_spread_pct',
        'confirmation_score', 'btc_trend', 'momentum_score', 'volume_quality',
        'structure_score',
        'total_score', 'momentum_rank', 'volume_rank', 'oi_rank',
        'breakout_rank', 'orderflow_rank', 'market_rank',
        'btc_price_at_signal', 'btc_change_1h', 'market_session',
        'tier', 'source', 'outcome_tracked',
    ];

    protected $casts = [
        'signaled_at' => 'datetime',
        'discovery_score' => 'float',
        'volume_acceleration' => 'float',
        'price_acceleration' => 'float',
        'relative_volume' => 'float',
        'trade_count' => 'integer',
        'quote_volume' => 'float',
        'price_change_24h' => 'float',
        'bid_ask_spread_pct' => 'float',
        'confirmation_score' => 'float',
        'momentum_score' => 'float',
        'volume_quality' => 'float',
        'structure_score' => 'float',
        'total_score' => 'float',
        'momentum_rank' => 'float',
        'volume_rank' => 'float',
        'oi_rank' => 'float',
        'breakout_rank' => 'float',
        'orderflow_rank' => 'float',
        'market_rank' => 'float',
        'btc_price_at_signal' => 'float',
        'btc_change_1h' => 'float',
        'outcome_tracked' => 'boolean',
    ];

    public function outcome(): HasOne
    {
        return $this->hasOne(SignalOutcome::class);
    }
}
