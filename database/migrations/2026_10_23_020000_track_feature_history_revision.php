<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_history_backfills', function (Blueprint $table): void {
            $table->unsignedBigInteger('feature_revision')->default(0);
        });

        DB::table('market_history_backfills')->update(['feature_revision' => DB::raw('trained_revision')]);
    }

    public function down(): void
    {
        Schema::table('market_history_backfills', function (Blueprint $table): void {
            $table->dropColumn('feature_revision');
        });
    }
};
