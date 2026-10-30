<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('recording_identity', 160)->nullable()->index();
            $table->string('recording_provenance', 160)->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('tracks')->restrictOnDelete();
        });
        Schema::create('member_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_version');
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->string('kind', 24);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('payload');
            $table->json('snapshot');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->string('resolution', 24)->nullable();
            $table->foreignId('result_playlist_id')->nullable()->constrained('playlists')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'created_at', 'id']);
            $table->index(['user_id', 'created_at', 'id']);
        });
        Schema::create('catalog_change_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_version');
            $table->string('operation', 24);
            $table->string('status', 24)->default('previewed');
            $table->unsignedBigInteger('revision')->default(0);
            $table->char('snapshot_hash', 64);
            $table->json('input');
            $table->json('scope');
            $table->json('before');
            $table->json('after')->nullable();
            $table->json('summary');
            $table->json('result')->nullable();
            $table->foreignUuid('proposal_id')->nullable()->constrained('member_proposals')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_change_sets');
        Schema::dropIfExists('member_proposals');
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropIndex(['recording_identity']);
            $table->dropColumn(['recording_identity', 'recording_provenance']);
        });
    }
};
