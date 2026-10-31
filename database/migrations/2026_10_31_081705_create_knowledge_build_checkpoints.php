<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_build_checkpoints', function (Blueprint $table) {
            $table->string('build_key', 64)->primary();
            $table->uuid('owner');
            $table->longText('state');
            $table->string('sha256', 64);
            $table->timestamp('updated_at');
        });
        Schema::create('knowledge_build_checkpoint_chunks', function (Blueprint $table) {
            $table->string('build_key', 64);
            $table->string('stream', 40);
            $table->unsignedBigInteger('chunk');
            $table->longText('payload');
            $table->string('sha256', 64);
            $table->primary(['build_key', 'stream', 'chunk'], 'knowledge_checkpoint_chunk_pk');
            $table->foreign('build_key')->references('build_key')->on('knowledge_build_checkpoints')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_build_checkpoint_chunks');
        Schema::dropIfExists('knowledge_build_checkpoints');
    }
};
