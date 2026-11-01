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
        Schema::create('source_veille', function (Blueprint $table) {
            $table->unsignedBigInteger('veille_id');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['veille_id', 'source_id']);
            $table->foreign(['veille_id', 'user_id'])
                ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
            $table->foreign('source_id')->references('id')->on('sources')->cascadeOnDelete();
            $table->index('source_id'); // FK enfant + lookup inverse matching
            $table->index(['veille_id', 'user_id']); // FK composite enfant
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_veille');
    }
};
