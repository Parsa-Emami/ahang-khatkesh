<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_key', 64);
            $table->string('session_binding', 64);
            $table->string('name', 120);
            $table->string('user_agent', 255)->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_key']);
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('playback_leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('playback_devices')->nullOnDelete();
            $table->unsignedBigInteger('generation')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->string('state', 16)->default('idle');
            $table->unsignedBigInteger('revision')->default(0);
            $table->foreignUuid('transfer_target_id')->nullable()->constrained('playback_devices')->nullOnDelete();
            $table->timestamp('transfer_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('playback_device_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('target_device_id')->constrained('playback_devices')->cascadeOnDelete();
            $table->foreignUuid('source_device_id')->nullable()->constrained('playback_devices')->nullOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->unsignedBigInteger('lease_generation');
            $table->string('type', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->unique(['target_device_id', 'sequence']);
            $table->index(['target_device_id', 'consumed_at', 'invalidated_at']);
        });

        Schema::create('offline_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->string('asset_sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->string('status', 16);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'track_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('offline_opt_in')->default(false);
            $table->timestamp('offline_revocation_acknowledged_at')->nullable();
            $table->unsignedBigInteger('offline_quota_bytes')->default(524288000);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['offline_opt_in', 'offline_revocation_acknowledged_at', 'offline_quota_bytes']);
        });
        Schema::dropIfExists('offline_downloads');
        Schema::dropIfExists('playback_device_commands');
        Schema::dropIfExists('playback_leases');
        Schema::dropIfExists('playback_devices');
    }
};
