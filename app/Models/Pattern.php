<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pattern extends Model
{
    protected $fillable = [
        'discovered_at', 'conditions', 'total_signals', 'win_count',
        'win_rate', 'avg_gain', 'avg_drawdown', 'best_gain', 'worst_drawdown',
        'sample_size', 'confidence',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'conditions' => 'array',
        'total_signals' => 'integer',
        'win_count' => 'integer',
        'win_rate' => 'float',
        'avg_gain' => 'float',
        'avg_drawdown' => 'float',
        'best_gain' => 'float',
        'worst_drawdown' => 'float',
        'sample_size' => 'integer',
    ];
}
