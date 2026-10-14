<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_market_settings', function (Blueprint $table): void {
            $table->dropColumn(['trading_enabled', 'paper_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('client_market_settings', function (Blueprint $table): void {
            $table->boolean('trading_enabled')->default(false);
            $table->boolean('paper_enabled')->default(false);
        });
    }
};
