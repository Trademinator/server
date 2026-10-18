<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('intelligence_tournaments', function (Blueprint $table): void {
            $table->uuid('dataset_id')->primary();
            $table->string('market_key', 64)->index();
            $table->json('report');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_tournaments');
    }
};
