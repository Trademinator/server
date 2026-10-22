<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('independent_human_annotations', function (Blueprint $table): void {
            $table->uuid('annotation_id')->primary();
            $table->uuid('trainer_id');
            $table->string('exchange', 64);
            $table->string('symbol', 128);
            $table->string('period', 16);
            $table->string('source', 24);
            $table->unsignedBigInteger('microtimestamp');
            $table->string('candle_sha256', 64);
            $table->string('label', 40);
            $table->timestamp('recorded_at');
            $table->unique(['trainer_id', 'exchange', 'symbol', 'period', 'source', 'microtimestamp', 'candle_sha256'], 'independent_human_unique_opinion');
            $table->index(['exchange', 'symbol', 'period', 'source', 'microtimestamp'], 'independent_human_market_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('independent_human_annotations');
    }
};
