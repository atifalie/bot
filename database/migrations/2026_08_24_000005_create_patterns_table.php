<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patterns', function (Blueprint $table) {
            $table->id();
            $table->dateTime('discovered_at');
            $table->json('conditions');
            $table->unsignedInteger('total_signals')->default(0);
            $table->unsignedInteger('win_count')->default(0);
            $table->decimal('win_rate', 6, 3)->default(0);
            $table->decimal('avg_gain', 14, 4)->default(0);
            $table->decimal('avg_drawdown', 14, 4)->default(0);
            $table->decimal('best_gain', 14, 4)->default(0);
            $table->decimal('worst_drawdown', 14, 4)->default(0);
            $table->unsignedInteger('sample_size')->default(0);
            $table->string('confidence', 10)->default('LOW');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patterns');
    }
};
