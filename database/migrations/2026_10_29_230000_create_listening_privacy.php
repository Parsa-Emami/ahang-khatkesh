<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('listening_history_enabled')->default(false);
            $table->boolean('listening_recommendations_enabled')->default(true);
            $table->unsignedSmallInteger('listening_retention_days')->default(30);
            $table->unsignedBigInteger('listening_revision')->default(0);
            $table->timestamp('listening_consent_at')->nullable();
            $table->timestamp('listening_private_until')->nullable();
        });
        Schema::create('listening_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->char('session_pseudonym', 64);
            $table->timestamp('started_at');
            $table->timestamp('last_event_at');
            $table->timestamp('ended_at')->nullable();
            $table->double('listened_seconds');
            $table->string('outcome', 12);
            $table->index(['user_id', 'started_at', 'id'], 'listening_user_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listening_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['listening_history_enabled', 'listening_recommendations_enabled', 'listening_retention_days', 'listening_revision', 'listening_consent_at', 'listening_private_until']));
    }
};
