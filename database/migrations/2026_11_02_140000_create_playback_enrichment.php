<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_lyrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('track_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('format', 8);
            $table->string('source', 16);
            $table->string('license_note', 500)->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->decimal('loudness_lufs', 6, 2)->nullable();
            $table->decimal('loudness_peak', 6, 2)->nullable();
            $table->timestamp('loudness_analyzed_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('crossfade_enabled')->default(false);
            $table->unsignedTinyInteger('crossfade_seconds')->default(3);
            $table->timestamp('crossfade_acknowledged_at')->nullable();
            $table->boolean('loudness_normalization')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['crossfade_enabled', 'crossfade_seconds', 'crossfade_acknowledged_at', 'loudness_normalization']));
        Schema::table('media_assets', fn (Blueprint $table) => $table->dropColumn(['loudness_lufs', 'loudness_peak', 'loudness_analyzed_at']));
        Schema::dropIfExists('track_lyrics');
    }
};
