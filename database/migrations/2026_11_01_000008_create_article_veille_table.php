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
        Schema::create('article_veille', function (Blueprint $table) {
            $table->unsignedBigInteger('veille_id');
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('user_id');
            $table->string('matched_keyword')->nullable();
            $table->timestamp('created_at', 6); // timestamp(6) — bornes des digests
            $table->primary(['veille_id', 'article_id']);
            $table->foreign(['veille_id', 'user_id'])
                ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->index('article_id');
            $table->index(['veille_id', 'user_id']);
            $table->index(['veille_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_veille');
    }
};
