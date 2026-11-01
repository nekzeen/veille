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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->restrictOnDelete();
            $table->string('guid', 512)->nullable();
            $table->char('dedup_key', 64);
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->char('content_hash', 64)->nullable();
            $table->string('title', 512);
            $table->string('author')->nullable();
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['source_id', 'dedup_key']);
            $table->index('source_id');
            $table->index(['source_id', 'url_hash']);
            $table->index(['source_id', 'published_at']);
            $table->index('published_at');
            $table->index('collected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
