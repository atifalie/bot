<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->dateTime('signaled_at')->index();
            $table->string('symbol', 20)->index();
            $table->string('timeframe', 10)->default('5m');

            $table->decimal('discovery_score', 8, 3)->nullable();
            $table->decimal('volume_acceleration', 14, 4)->nullable();
            $table->decimal('price_acceleration', 14, 4)->nullable();
            $table->decimal('relative_volume', 14, 4)->nullable();
            $table->unsignedBigInteger('trade_count')->nullable();
            $table->decimal('quote_volume', 24, 4)->nullable();
            $table->decimal('price_change_24h', 10, 4)->nullable();
            $table->decimal('bid_ask_spread_pct', 10, 4)->nullable();

            $table->decimal('confirmation_score', 8, 3)->nullable();
            $table->string('btc_trend', 20)->nullable();
            $table->decimal('momentum_score', 8, 3)->nullable();
            $table->decimal('volume_quality', 8, 3)->nullable();
            $table->decimal('structure_score', 8, 3)->nullable();

            $table->decimal('total_score', 8, 3)->nullable()->index();
            $table->decimal('momentum_rank', 8, 3)->nullable();
            $table->decimal('volume_rank', 8, 3)->nullable();
            $table->decimal('oi_rank', 8, 3)->nullable();
            $table->decimal('breakout_rank', 8, 3)->nullable();
            $table->decimal('orderflow_rank', 8, 3)->nullable();
            $table->decimal('market_rank', 8, 3)->nullable();

            $table->decimal('btc_price_at_signal', 20, 8)->nullable();
            $table->decimal('btc_change_1h', 10, 4)->nullable();
            $table->string('market_session', 20)->nullable();

            $table->string('tier', 20)->nullable();
            $table->string('source', 20)->default('scanner');

            $table->boolean('outcome_tracked')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
