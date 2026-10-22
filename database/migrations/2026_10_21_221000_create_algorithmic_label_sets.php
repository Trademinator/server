<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('algorithmic_label_sets', function (Blueprint $table): void {
            $table->uuid('label_set_id')->primary();
            $table->string('exchange', 64);
            $table->string('symbol', 128);
            $table->string('period', 16);
            $table->unsignedBigInteger('as_of_ms');
            $table->unsignedBigInteger('history_revision')->default(0);
            $table->string('action_version', 100);
            $table->string('outcome_version', 100);
            $table->string('profile_sha256', 64);
            $table->string('sha256', 64);
            $table->longText('payload');
            $table->timestamp('created_at');
            $table->unique(['exchange', 'symbol', 'period', 'as_of_ms', 'history_revision', 'profile_sha256'], 'label_sets_revision');
            $table->index(['exchange', 'symbol', 'period', 'as_of_ms'], 'label_sets_market_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('algorithmic_label_sets');
    }
};
