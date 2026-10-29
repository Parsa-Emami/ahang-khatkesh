<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->default('fa-IR');
            $table->string('direction', 4)->default('auto');
            $table->string('motion', 7)->default('system');
            $table->string('time_zone', 64)->default('Asia/Tehran');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locale', 'direction', 'motion', 'time_zone']));
    }
};
