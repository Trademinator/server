<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tournament_shadow_models', function (Blueprint $table): void {
            $table->uuid('shadow_model_id')->primary();
            $table->uuid('dataset_id')->index();
            $table->char('market_key', 64)->index();
            $table->string('task', 16);
            $table->string('algorithm', 32);
            $table->string('version', 128);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('trained_until_ms');
            $table->unsignedBigInteger('published_at_ms');
            $table->json('metadata');
            $table->timestamps();
            $table->unique(['dataset_id', 'task', 'algorithm'], 'shadow_model_version_unique');
        });
        Schema::create('tournament_shadow_predictions', function (Blueprint $table): void {
            $table->uuid('shadow_prediction_id')->primary();
            $table->uuid('shadow_model_id');
            $table->char('market_key', 64)->index();
            $table->unsignedBigInteger('decision_at_ms');
            $table->unsignedBigInteger('predicted_at_ms');
            $table->string('prediction', 24)->nullable();
            $table->boolean('supported');
            $table->decimal('confidence', 12, 10);
            $table->json('probabilities');
            $table->string('reason', 64);
            $table->char('feature_sha256', 64);
            $table->timestamps();
            $table->unique(['shadow_model_id', 'decision_at_ms'], 'shadow_one_prediction_per_candle');
        });
        Schema::create('tournament_shadow_scores', function (Blueprint $table): void {
            $table->uuid('shadow_prediction_id')->primary();
            $table->string('actual', 24);
            $table->unsignedBigInteger('label_available_at_ms');
            $table->unsignedBigInteger('scored_at_ms');
            $table->boolean('correct')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_shadow_scores');
        Schema::dropIfExists('tournament_shadow_predictions');
        Schema::dropIfExists('tournament_shadow_models');
    }
};
