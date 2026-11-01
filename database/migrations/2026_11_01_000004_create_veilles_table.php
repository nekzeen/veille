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
        Schema::create('veilles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('alerts_enabled')->default(false);
            $table->timestamps(6); // timestamp(6) — borne initiale des digests
            $table->unique(['user_id', 'name']);
            $table->unique(['id', 'user_id']); // parent des FK composites enfants
            $table->index('user_id');
            $table->index(['alerts_enabled', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('veilles');
    }
};
