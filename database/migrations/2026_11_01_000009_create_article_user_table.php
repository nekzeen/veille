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
        Schema::create('article_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->primary(['user_id', 'article_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign(['user_id', 'category_id'])
                ->references(['user_id', 'id'])->on('categories')->restrictOnDelete();
            $table->index('article_id');
            $table->index(['user_id', 'category_id']); // FK composite enfant + navigation
            $table->index(['user_id', 'is_favorite']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_user');
    }
};
