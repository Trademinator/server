<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_build_checkpoints', function (Blueprint $table): void {
            $table->string('kind', 24)->nullable();
            $table->string('exchange', 64)->nullable();
            $table->string('symbol', 128)->nullable();
            $table->string('period', 16)->nullable();
            $table->index(['exchange', 'symbol', 'period', 'kind'], 'knowledge_checkpoint_scope');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_build_checkpoints', function (Blueprint $table): void {
            $table->dropIndex('knowledge_checkpoint_scope');
            $table->dropColumn(['kind', 'exchange', 'symbol', 'period']);
        });
    }
};
