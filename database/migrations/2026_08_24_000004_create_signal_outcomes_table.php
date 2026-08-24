<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained('signals')->cascadeOnDelete()->index();
            $table->string('symbol', 20)->index();
            $table->decimal('price_at_signal', 20, 8);

            foreach ([15 => '15m', 30 => '30m', 60 => '1h', 240 => '4h', 1440 => '24h'] as $_ => $suffix) {
                $table->decimal("max_gain_{$suffix}", 14, 4)->nullable();
                $table->decimal("max_drawdown_{$suffix}", 14, 4)->nullable();
            }

            $table->boolean('reached_5pct')->default(false);
            $table->boolean('reached_10pct')->default(false);
            $table->boolean('reached_minus_5pct')->default(false);

            $table->dateTime('tracked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_outcomes');
    }
};
