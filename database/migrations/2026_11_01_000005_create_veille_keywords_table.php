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
        Schema::create('veille_keywords', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('veille_id');
            $table->unsignedBigInteger('user_id');
            $table->string('term');
            $table->timestamps();
            $table->foreign(['veille_id', 'user_id'])
                ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
            $table->unique(['veille_id', 'term']);
            $table->index(['veille_id', 'user_id']); // index enfant explicite de la FK
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('veille_keywords');
    }
};
