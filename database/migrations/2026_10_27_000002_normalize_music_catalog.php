<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('sort_name')->nullable();
            $t->text('description')->nullable();
            $t->char('country_code', 2)->nullable();
            $t->text('search_text');
            $t->string('provenance', 64)->nullable()->unique();
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamps();
            $t->index('name');
        });
        Schema::create('releases', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->enum('type', ['album', 'single', 'ep', 'compilation'])->default('album');
            $t->date('released_on')->nullable();
            $t->text('search_text');
            $t->string('provenance', 64)->nullable()->unique();
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamps();
            $t->index(['released_on', 'id']);
        });
        Schema::create('artist_track', function (Blueprint $t): void {
            $t->foreignId('artist_id')->constrained()->restrictOnDelete();
            $t->foreignId('track_id')->constrained()->cascadeOnDelete();
            $t->enum('role', ['primary', 'featured', 'composer', 'producer']);
            $t->unsignedSmallInteger('position');
            $t->primary(['track_id', 'artist_id', 'role']);
            $t->unique(['track_id', 'role', 'position']);
        });
        Schema::create('artist_release', function (Blueprint $t): void {
            $t->foreignId('artist_id')->constrained()->restrictOnDelete();
            $t->foreignId('release_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->primary(['release_id', 'artist_id']);
            $t->unique(['release_id', 'position']);
        });
        Schema::create('release_track', function (Blueprint $t): void {
            $t->foreignId('release_id')->constrained()->cascadeOnDelete();
            $t->foreignId('track_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('disc_number')->default(1);
            $t->unsignedSmallInteger('track_number');
            $t->primary(['release_id', 'disc_number', 'track_number']);
            $t->unique(['release_id', 'track_id']);
            $t->index('track_id');
        });
        Schema::create('track_media', function (Blueprint $t): void {
            $t->foreignId('track_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('media_asset_id')->unique()->constrained()->restrictOnDelete();
            $t->enum('purpose', ['original', 'rendition']);
            $t->string('label', 80);
            $t->primary(['track_id', 'media_asset_id']);
            $t->unique(['track_id', 'label']);
        });
        Schema::table('tracks', function (Blueprint $t): void {
            $t->unsignedBigInteger('catalog_revision')->default(0);
            $t->foreignId('primary_release_id')->nullable()->constrained('releases')->restrictOnDelete();
        });
        Schema::table('playlists', fn (Blueprint $t) => $t->unsignedBigInteger('revision')->default(0));
        DB::table('tracks')->orderBy('id')->chunkById(100, function ($tracks): void {
            foreach ($tracks as $track) {
                $artist = DB::table('artists')->insertGetId(['name' => $track->artist, 'search_text' => $track->artist, 'provenance' => 'legacy:track:'.$track->id, 'created_at' => $track->created_at, 'updated_at' => $track->updated_at]);
                DB::table('artist_track')->insert(['artist_id' => $artist, 'track_id' => $track->id, 'role' => 'primary', 'position' => 1]);
                DB::table('track_media')->insert(['track_id' => $track->id, 'media_asset_id' => $track->media_asset_id, 'purpose' => 'original', 'label' => 'original']);
                if ($track->album !== null && trim($track->album) !== '') {
                    $release = DB::table('releases')->insertGetId(['title' => $track->album, 'type' => 'album', 'search_text' => $track->album, 'provenance' => 'legacy:track:'.$track->id, 'created_at' => $track->created_at, 'updated_at' => $track->updated_at]);
                    DB::table('artist_release')->insert(['artist_id' => $artist, 'release_id' => $release, 'position' => 1]);
                    DB::table('release_track')->insert(['release_id' => $release, 'track_id' => $track->id, 'disc_number' => 1, 'track_number' => 1]);
                    DB::table('tracks')->where('id', $track->id)->update(['primary_release_id' => $release]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('playlists', fn (Blueprint $t) => $t->dropColumn('revision'));
        Schema::table('tracks', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('primary_release_id');
            $t->dropColumn('catalog_revision');
        });
        foreach (['track_media', 'release_track', 'artist_release', 'artist_track', 'releases', 'artists'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
