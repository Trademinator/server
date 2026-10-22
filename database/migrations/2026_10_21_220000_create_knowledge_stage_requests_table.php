<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_stage_requests', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->string('exchange', 80);
            $table->string('symbol', 100);
            $table->string('period', 8);
            $table->string('stage', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('required_through_ms')->nullable();
            $table->unsignedBigInteger('completed_through_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['exchange', 'symbol', 'period', 'stage', 'status'], 'knowledge_requests_scope_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_stage_requests');
    }
};
