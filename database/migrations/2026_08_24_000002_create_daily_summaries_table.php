<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('trade_date')->unique();
            $table->unsignedInteger('total_trades')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->decimal('total_pnl_percent', 10, 4)->default(0);
            $table->decimal('total_pnl_usdt', 20, 8)->default(0);
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->decimal('best_trade_pnl', 10, 4)->default(0);
            $table->decimal('worst_trade_pnl', 10, 4)->default(0);
            $table->decimal('closing_balance', 20, 8)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
