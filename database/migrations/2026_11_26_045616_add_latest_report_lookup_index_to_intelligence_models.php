<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intelligence_models', function (Blueprint $table) {
            $table->index(['market_key', 'created_at', 'model_id'], 'intelligence_models_latest_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('intelligence_models', function (Blueprint $table) {
            $table->dropIndex('intelligence_models_latest_lookup');
        });
    }
};
