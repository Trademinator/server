<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('algorithmic_label_chunks', function (Blueprint $table) {
            $table->uuid('label_set_id');
            $table->string('kind', 32);
            $table->unsignedInteger('chunk');
            $table->unsignedBigInteger('first_ms');
            $table->unsignedBigInteger('last_ms');
            $table->unsignedInteger('row_count');
            $table->string('sha256', 64);
            $table->longText('payload');
            $table->primary(['label_set_id', 'kind', 'chunk'], 'label_chunks_primary');
            $table->index(['label_set_id', 'kind', 'last_ms'], 'label_chunks_timestamp');
            $table->foreign('label_set_id')->references('label_set_id')->on('algorithmic_label_sets')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('algorithmic_label_chunks');
    }
};
