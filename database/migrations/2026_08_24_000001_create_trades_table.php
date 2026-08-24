<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->index();
            $table->string('direction', 10)->default('BUY');
            $table->string('status', 20)->default('OPEN')->index();
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8)->nullable();
            $table->decimal('stop_loss', 20, 8)->nullable();
            $table->decimal('take_profit', 20, 8)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->decimal('pnl_percent', 10, 4)->nullable();
            $table->decimal('pnl_usdt', 20, 8)->nullable();
            $table->string('close_reason', 50)->nullable();
            $table->dateTime('opened_at')->index();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
