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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('feed_url', 512)->unique();
            $table->string('name');
            $table->string('site_url', 512)->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_catalogue')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'last_fetched_at']);
            $table->index('is_catalogue');
            $table->index('orphaned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
