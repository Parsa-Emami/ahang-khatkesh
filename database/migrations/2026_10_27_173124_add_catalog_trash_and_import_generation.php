<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('playlists', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('playlist_imports', fn (Blueprint $table) => $table->unsignedInteger('generation')->default(0));
    }

    public function down(): void
    {
        Schema::table('playlist_imports', fn (Blueprint $table) => $table->dropColumn('generation'));
        Schema::table('playlists', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('tracks', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
