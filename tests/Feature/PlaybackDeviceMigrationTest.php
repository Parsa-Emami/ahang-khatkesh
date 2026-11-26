<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlaybackDeviceMigrationTest extends TestCase
{
    public function test_interrupted_device_migration_completes_without_replacing_existing_rows(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.playback_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('playback_upgrade');

        try {
            (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
            (require database_path('migrations/2026_10_26_091444_create_music_library.php'))->up();
            $migration = require database_path('migrations/2026_11_02_160000_create_playback_devices.php');
            $migration->up();

            $userId = DB::table('users')->insertGetId(['name' => 'Upgrade fixture', 'email' => 'upgrade@example.test', 'password' => 'unused-test-hash']);
            $deviceId = '121980fd-28ef-41f7-9d3f-86b936d5bff9';
            DB::table('playback_devices')->insert(['id' => $deviceId, 'user_id' => $userId, 'device_key' => 'fixture-device', 'session_binding' => 'fixture-session', 'name' => 'Existing device']);
            Schema::table('playback_device_commands', fn (Blueprint $table) => $table->dropIndex('playback_commands_target_status_idx'));
            Schema::drop('offline_downloads');
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['offline_opt_in', 'offline_revocation_acknowledged_at', 'offline_quota_bytes']));

            $migration->up();
            $migration->up();

            $this->assertTrue(Schema::hasIndex('playback_device_commands', ['target_device_id', 'consumed_at', 'invalidated_at']));
            $this->assertTrue(Schema::hasTable('offline_downloads'));
            $this->assertSame(524288000, (int) DB::table('users')->where('id', $userId)->value('offline_quota_bytes'));
            $this->assertSame('Existing device', DB::table('playback_devices')->where('id', $deviceId)->value('name'));
        } finally {
            DB::purge('playback_upgrade');
            DB::setDefaultConnection($original);
        }
    }
}
