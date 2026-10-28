<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_settings', function (Blueprint $table): void {
            $table->boolean('media_inventory_ready')->default(false);
            $table->string('media_inventory_cursor')->nullable();
            $table->string('media_gc_cursor')->nullable();
        });
        Schema::create('media_blobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->string('state', 16)->default('ready');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['sha256', 'size_bytes']);
        });
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropUnique(['sha256']);
            $table->foreignUuid('blob_id')->nullable()->constrained('media_blobs')->restrictOnDelete();
        });
        Schema::table('playlists', fn (Blueprint $table) => $table->foreignUuid('cover_blob_id')->nullable()->constrained('media_blobs')->restrictOnDelete());
        Schema::create('storage_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('access_version');
            $table->string('purpose', 12);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 24)->default('reserved');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('expires_at');
            $table->string('staging_path')->nullable();
            $table->string('candidate_path')->nullable();
            $table->foreignUuid('result_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->foreignUuid('result_blob_id')->nullable()->constrained('media_blobs')->nullOnDelete();
            $table->json('intent')->nullable();
            $table->timestamp('lock_pruned_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'lock_pruned_at', 'updated_at'], 'reservation_lock_retention');
        });
        Schema::create('media_garbage_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('eligible_at');
            $table->timestamps();
            $table->index(['eligible_at', 'id']);
        });
        Schema::table('playlist_imports', function (Blueprint $table): void {
            $table->uuid('execution_token')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('recovery_count')->default(0);
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('media_assets')->select('sha256')->groupBy('sha256')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Independent assets now share bytes. Restore a coherent backup or apply a forward fix instead of downgrading.');
        }
        Schema::table('playlist_imports', function (Blueprint $table): void {
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn(['execution_token', 'queued_at', 'heartbeat_at', 'lease_expires_at', 'recovery_count']);
        });
        Schema::dropIfExists('media_garbage_candidates');
        Schema::dropIfExists('storage_reservations');
        Schema::table('playlists', fn (Blueprint $table) => $table->dropConstrainedForeignId('cover_blob_id'));
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('blob_id');
            $table->unique('sha256');
        });
        Schema::dropIfExists('media_blobs');
        Schema::table('library_settings', fn (Blueprint $table) => $table->dropColumn(['media_inventory_ready', 'media_inventory_cursor', 'media_gc_cursor']));
    }
};
