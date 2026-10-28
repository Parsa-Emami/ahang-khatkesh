<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->foreignUuid('source_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->unsignedBigInteger('ownership_revision')->default(1);
            $table->string('codec', 32)->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
        });
        Schema::create('media_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->string('profile_id', 40);
            $table->unsignedInteger('profile_version');
            $table->string('status', 24);
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('actor_epoch');
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_revision');
            $table->char('ownership_hash', 64);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedTinyInteger('automatic_retries')->default(0);
            $table->decimal('progress_seconds', 12, 3)->default(0);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->foreignUuid('output_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->string('error_code', 40)->nullable();
            $table->json('report')->nullable();
            $table->timestamps();
            $table->unique(['source_asset_id', 'profile_id', 'profile_version'], 'conversion_source_profile');
            $table->index(['status', 'lease_expires_at']);
            $table->index(['status', 'next_retry_at']);
        });
        Schema::create('media_conversion_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversion_id')->constrained('media_conversions')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->foreignUuid('reservation_id')->unique()->constrained('storage_reservations')->restrictOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['conversion_id', 'generation']);
        });
    }

    public function down(): void
    {
        if (DB::table('media_conversions')->exists()) {
            throw new RuntimeException('Conversions retain lineage and media. Use a forward fix or a coherent backup instead of destructive downgrade.');
        }
        Schema::dropIfExists('media_conversion_attempts');
        Schema::dropIfExists('media_conversions');
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_asset_id');
            $table->dropColumn(['ownership_revision', 'codec', 'sample_rate', 'channels']);
        });
    }
};
