<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogUpgradeTest extends TestCase
{
    public function test_legacy_upgrade_preserves_recording_ids_likes_and_playlist_order(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.upgrade_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('upgrade_fixture');
        try {
            foreach (['0001_01_01_000000_create_users_table', '2026_10_26_091444_create_music_library'] as $name) {
                (require database_path('migrations/'.$name.'.php'))->up();
            }
            $time = now();
            DB::table('users')->insert(['id' => 7, 'name' => 'مالک قبلی', 'email' => 'legacy@example.test', 'password' => 'unused-test-hash', 'is_admin' => true, 'is_active' => true]);
            DB::table('playlists')->insert(['id' => 19, 'name' => 'مجموعهٔ قدیمی', 'search_text' => 'قدیمی', 'created_by' => 7, 'created_at' => $time, 'updated_at' => $time]);
            foreach ([41, 42] as $i => $id) {
                $asset = 'b5eb3e98-9402-4d51-a35d-0000000000'.($i + 10);
                DB::table('media_assets')->insert(['id' => $asset, 'sha256' => str_repeat((string) $i, 64), 'path' => 'private-fixture-'.$i, 'original_name' => 'fixture.wav', 'mime_type' => 'audio/wav', 'size_bytes' => 1000, 'duration_seconds' => 1, 'uploaded_by' => 7]);
                DB::table('tracks')->insert(['id' => $id, 'media_asset_id' => $asset, 'title' => 'آهنگ قدیمی', 'artist' => 'نام مشترک', 'album' => 'عنوان مشترک', 'search_text' => 'قدیمی', 'created_at' => $time, 'updated_at' => $time]);
                DB::table('playlist_track')->insert(['playlist_id' => 19, 'track_id' => $id, 'position' => $i + 1]);
            }
            DB::table('liked_tracks')->insert(['user_id' => 7, 'track_id' => 42, 'created_at' => $time, 'updated_at' => $time]);
            foreach (['2026_10_27_000001_add_administration_security', '2026_10_27_000002_normalize_music_catalog'] as $name) {
                (require database_path('migrations/'.$name.'.php'))->up();
            }
            $this->assertSame('owner', DB::table('users')->where('id', 7)->value('role'));
            $this->assertSame([41, 42], DB::table('tracks')->orderBy('id')->pluck('id')->all());
            $this->assertSame([41, 42], DB::table('playlist_track')->where('playlist_id', 19)->orderBy('position')->pluck('track_id')->all());
            $this->assertSame(1, DB::table('liked_tracks')->where(['user_id' => 7, 'track_id' => 42])->count());
            $this->assertSame(2, DB::table('artists')->count());
            $this->assertSame(2, DB::table('releases')->count());
            $this->assertSame(2, DB::table('track_media')->where('purpose', 'original')->count());
            $this->assertSame(2, DB::table('tracks')->whereNotNull('primary_release_id')->count());
            foreach (['2026_10_27_173124_add_catalog_trash_and_import_generation', '2026_10_28_072416_add_durable_media_storage_and_import_leases', '2026_10_28_124654_add_audio_conversion_pipeline', '2026_10_29_130000_add_user_interface_preferences', '2026_10_29_230000_create_listening_privacy', '2026_10_30_034717_add_catalog_workflows'] as $name) {
                (require database_path('migrations/'.$name.'.php'))->up();
            }
            $this->assertSame([41, 42], DB::table('tracks')->orderBy('id')->pluck('id')->all());
            $this->assertSame([41, 42], DB::table('playlist_track')->where('playlist_id', 19)->orderBy('position')->pluck('track_id')->all());
            $this->assertSame(1, DB::table('liked_tracks')->where(['user_id' => 7, 'track_id' => 42])->count());
            $this->assertSame(2, DB::table('tracks')->whereNull('merged_into_id')->whereNull('recording_identity')->whereNull('recording_provenance')->count());
            $this->assertSame(0, DB::table('catalog_change_sets')->count());
            $this->assertSame(0, DB::table('member_proposals')->count());

        } finally {
            DB::purge('upgrade_fixture');
            DB::setDefaultConnection($original);
        }
    }
}
