<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->boolean('is_admin')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('theme', 10)->default('system');
        });
        Schema::create('library_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lock_version')->default(0);
            $t->timestamps();
        });
        DB::table('library_settings')->insert(['id' => 1, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('media_assets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->char('sha256', 64)->unique();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->decimal('duration_seconds', 10, 3);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('tracks', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('media_asset_id')->unique()->constrained()->restrictOnDelete();
            $t->string('title');
            $t->string('artist');
            $t->string('album')->nullable();
            $t->text('search_text');
            $t->timestamps();
        });
        Schema::create('playlists', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->string('mood', 20)->default('other');
            $t->string('status', 16)->default('draft');
            $t->string('cover_path')->nullable();
            $t->unsignedInteger('artwork_seed')->default(0);
            $t->text('search_text');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        Schema::create('playlist_track', function (Blueprint $t): void {
            $t->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $t->foreignId('track_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->primary(['playlist_id', 'track_id']);
            $t->unique(['playlist_id', 'position']);
            $t->index('track_id');
        });
        Schema::create('playlist_imports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 64);
            $t->char('payload_hash', 64);
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->string('mood', 20);
            $t->json('rows');
            $t->string('status', 16)->default('ready');
            $t->json('report')->nullable();
            $t->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('publish')->default(true);
            $t->text('error')->nullable();
            $t->timestamps();
            $t->unique(['created_by', 'idempotency_key']);
            $t->index(['status', 'created_at']);
        });
        foreach (['liked_tracks' => 'track', 'saved_playlists' => 'playlist'] as $name => $related) {
            Schema::create($name, function (Blueprint $t) use ($related): void {
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId($related.'_id')->constrained()->cascadeOnDelete();
                $t->timestamps();
                $t->primary(['user_id', $related.'_id']);
            });
        }
        Schema::create('playback_states', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $t->json('queue');
            $t->decimal('position_seconds', 10, 3)->default(0);
            $t->decimal('volume', 4, 3)->default(0.8);
            $t->boolean('shuffle')->default(false);
            $t->string('repeat_mode', 8)->default('off');
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 80);
            $t->string('subject_id', 64)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'playback_states', 'saved_playlists', 'liked_tracks', 'playlist_imports', 'playlist_track', 'playlists', 'tracks', 'media_assets', 'library_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'is_active', 'theme']));
    }
};
