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
        Schema::create('veille_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('veille_id');
            $table->unsignedBigInteger('user_id');
            $table->date('digest_date');
            $table->enum('status', ['pending', 'processing', 'sent', 'failed'])->default('pending');
            $table->timestamp('period_start', 6)->nullable();
            $table->timestamp('period_end', 6)->nullable();
            $table->unsignedSmallInteger('articles_count')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('reserved_at', 6)->nullable();
            $table->char('reservation_token', 36)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['veille_id', 'digest_date']);
            $table->index(['veille_id', 'status', 'reserved_at']);
            $table->index(['user_id', 'sent_at']);
            $table->index(['veille_id', 'user_id']); // index enfant explicite de la FK composite
            $table->foreign(['veille_id', 'user_id'])
                ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('veille_alerts');
    }
};
