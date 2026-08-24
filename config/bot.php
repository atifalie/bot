<?php

$profile = strtolower(env('STRATEGY_PROFILE', 'swing'));

$marketProfiles = [
    'swing' => ['timeframe' => '15m', 'higher_timeframe' => '1h', 'htf_candle_lookback' => 120],
    'scalping' => ['timeframe' => '3m', 'higher_timeframe' => '15m', 'htf_candle_lookback' => 300],
];
$market = $marketProfiles[$profile] ?? $marketProfiles['swing'];

$indicatorProfiles = [
    'swing' => [
        'fast_ma_period' => 9, 'slow_ma_period' => 21, 'rsi_period' => 14,
        'atr_period' => 14, 'volume_ma_period' => 20,
        'macd_fast' => 12, 'macd_slow' => 26, 'macd_signal' => 9,
        'bb_period' => 20, 'bb_std_dev' => 2.0, 'stoch_rsi_period' => 14,
    ],
    'scalping' => [
        'fast_ma_period' => 5, 'slow_ma_period' => 13, 'rsi_period' => 9,
        'atr_period' => 10, 'volume_ma_period' => 10,
        'macd_fast' => 6, 'macd_slow' => 13, 'macd_signal' => 5,
        'bb_period' => 14, 'bb_std_dev' => 1.8, 'stoch_rsi_period' => 9,
    ],
];
$indicators = $indicatorProfiles[$profile] ?? $indicatorProfiles['swing'];

$riskProfiles = [
    'swing' => [
        'risk_per_trade_percent' => 1.5, 'atr_stop_loss_multiplier' => 2.0,
        'atr_take_profit_multiplier' => 4.0, 'min_risk_reward_ratio' => 1.5,
        'max_position_percent_of_balance' => 25, 'max_daily_loss_percent' => 5.0,
        'max_consecutive_losses' => 4, 'cooldown_minutes_after_max_losses' => 120,
        'max_drawdown_from_peak_percent' => 15.0,
    ],
    'scalping' => [
        'risk_per_trade_percent' => 0.5, 'atr_stop_loss_multiplier' => 1.2,
        'atr_take_profit_multiplier' => 1.8, 'min_risk_reward_ratio' => 1.4,
        'max_position_percent_of_balance' => 15, 'max_daily_loss_percent' => 3.0,
        'max_consecutive_losses' => 5, 'cooldown_minutes_after_max_losses' => 30,
        'max_drawdown_from_peak_percent' => 10.0,
    ],
];
$risk = $riskProfiles[$profile] ?? $riskProfiles['swing'];
$risk['max_drawdown_from_peak_percent'] = (float) (env('MAX_DRAWDOWN_PCT') ?: $risk['max_drawdown_from_peak_percent']);
$risk['min_notional_usdt'] = 5.0;
$risk['fixed_trade_size_usdt'] = (float) env('TRADE_SIZE_USDT', 10);
// Portfolio cap: itni open positions hone par flat coins ki entry-search band.
// Exits/trailing stops is cap se mutasir NAHI hote.
$risk['max_open_trades'] = (int) (env('MAX_OPEN_TRADES') ?: 5);

$calibrationProfiles = [
    'swing' => [
        'trend_separation_scale' => 50, 'macd_magnitude_scale' => 500,
        'atr_pct_dead_threshold' => 0.15, 'atr_pct_extreme_threshold' => 8.0,
        'atr_pct_critical_blocker' => 15.0, 'atr_pct_ideal_center' => 2.0,
    ],
    'scalping' => [
        'trend_separation_scale' => 180, 'macd_magnitude_scale' => 2500,
        'atr_pct_dead_threshold' => 0.04, 'atr_pct_extreme_threshold' => 0.8,
        'atr_pct_critical_blocker' => 1.1, 'atr_pct_ideal_center' => 0.22,
    ],
];
$calibration = $calibrationProfiles[$profile] ?? $calibrationProfiles['swing'];

$loopProfiles = ['swing' => 60, 'scalping' => 15];

$minConfidence = 70;
if (($envMinConf = trim((string) env('MIN_CONFIDENCE', ''))) !== '' && is_numeric($envMinConf)) {
    $minConfidence = max(0, min(95, (int) floatval($envMinConf)));
}

$exchangeId = strtolower(trim(env('EXCHANGE', 'binance')));
$keyEnvPrefix = strtoupper($exchangeId);

return [

    'profile' => $profile,

    'exchange' => [
        'id' => in_array($exchangeId, ['binance', 'bybit'], true) ? $exchangeId : 'binance',
        'api_key' => env($keyEnvPrefix.'_API_KEY', ''),
        'api_secret' => env($keyEnvPrefix.'_API_SECRET', ''),
        'use_testnet' => env('USE_TESTNET', true),
        'use_demo' => env('USE_DEMO', false),
        'request_timeout_ms' => 15000,
        'max_retries' => 3,
        'retry_backoff_seconds' => 2.0,
        'rate_limit_weight_warn_pct' => 60,
        'rate_limit_weight_pause_pct' => 85,
    ],

    'market' => [
        'symbols' => array_values(array_filter(array_map('trim', explode(',', (string) env('BOT_SYMBOLS', 'BTC/USDT,ETH/USDT'))))),
        // Dashboard Settings page overrides (empty = use profile defaults)
        'timeframe' => (trim((string) env('BOT_TIMEFRAME', '')) ?: $market['timeframe']),
        'higher_timeframe' => (trim((string) env('BOT_HIGHER_TIMEFRAME', '')) ?: $market['higher_timeframe']),
        'htf_candle_lookback' => $market['htf_candle_lookback'],
        'candle_lookback' => 300,
        'max_candle_gap_multiplier' => 2.5,
        // HTF candles barely change within minutes — cache them to halve API weight
        'htf_cache_timeframes' => ['1h', '2h', '4h', '1d'],
        'htf_cache_ttl_seconds' => (int) env('HTF_CACHE_TTL', 300),
    ],

    'scanner' => [
        'enabled' => env('SCANNER_ENABLED', false),
        'min_score' => 70,
        'max_symbols' => 8,
        'interval_minutes' => 5,
    ],

    'indicators' => $indicators,

    'calibration' => $calibration,

    'weights' => [
        'trend' => 0.18,
        'structure' => 0.15,
        'momentum' => 0.13,
        'regime' => 0.10,
        'bollinger' => 0.08,
        'volume' => 0.07,
        'macd' => 0.07,
        'vwap' => 0.06,
        'stoch_rsi' => 0.06,
        'htf_trend' => 0.06,
        'volatility' => 0.04,
        'reliability' => 0.00,
    ],

    'confidence_bands' => [
        'exceptional' => 80,
        'strong' => 70,
        'acceptable' => 62,
        'weak' => 55,
    ],

    'risk' => $risk,

    'validation' => [
        'min_candles_required' => 150,
        'max_allowed_null_percent' => 2.0,
        'min_confidence_to_act' => $minConfidence,
        'min_conflicting_signal_penalty' => 25,
        'htf_hard_gate' => true,
        'regime_split_gate' => true,
        'structure_min_ranging' => 65,
        'structure_min_trending' => 35,
    ],

    'trade_manager' => [
        'max_daily_trades' => 999,
        'min_minutes_between_trades' => 5,
        'daily_profit_target_percent' => 999.0,
        'daily_loss_limit_percent' => 10.0,
        'max_open_positions' => (int) env('MAX_OPEN_TRADES', 3),
        'indicator_exit_min_hold_hours' => $profile === 'swing' ? 6.0 : 0.5,
        // Backtest experiments: false = winners ride to TP on SL/TP alone
        'indicator_exit_enabled' => (bool) env('INDICATOR_EXIT_ENABLED', true),
    ],

    'session_filter' => [
        'enabled' => false,
        'require_high_quality' => false,
    ],

    'loop' => [
        'check_interval_seconds' => $loopProfiles[$profile] ?? 60,
    ],

    'backtest' => [
        'train_split_percent' => 0.6,
        'validation_split_percent' => 0.2,
        'walk_forward_window' => 500,
        'walk_forward_step' => 100,
    ],

    'memory' => [
        'outcome_windows_minutes' => [15, 30, 60, 240, 1440],
        'gain_thresholds_pct' => ['reached_5pct' => 5.0, 'reached_10pct' => 10.0, 'reached_minus_5pct' => -5.0],
        'track_batch_size' => 20,
        'max_signal_age_hours' => 24,
        'pattern_min_samples' => 10,
        'pattern_high_confidence_samples' => 100,
        'reliability_max_history' => 500,
        'reliability_min_samples' => 10,
    ],

    'notify' => [
        'email_enabled' => env('EMAIL_ENABLED', false),
        'smtp_server' => env('SMTP_SERVER', 'smtp.gmail.com'),
        'smtp_port' => (int) env('SMTP_PORT', 587),
        'email_address' => env('EMAIL_ADDRESS', ''),
        'email_to' => env('EMAIL_TO', ''),
    ],

    'openrouter' => [
        'enabled' => env('OPENROUTER_ENABLED', false),
        'api_key' => env('OPENROUTER_API_KEY', ''),
        'base_url' => 'https://openrouter.ai/api/v1/chat/completions',
        'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.1-8b-instruct:free'),
        'temperature' => 0.3,
        'max_tokens' => 1024,
        'requests_per_second' => 1,
        'max_retries' => 3,
    ],

    'ai_adjust_limits' => [
        'scanner_boost_max' => 15,
        'memory_boost_max' => 20,
    ],
];
