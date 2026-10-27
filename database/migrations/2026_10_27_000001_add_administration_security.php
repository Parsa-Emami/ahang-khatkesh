<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['owner', 'administrator', 'curator', 'listener'])->default('listener');
            $table->unsignedBigInteger('access_version')->default(0);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
        DB::table('users')->where('is_admin', true)->update(['role' => 'administrator']);
        $owner = DB::table('users')->where('is_admin', true)->orderByDesc('is_active')->orderBy('id')->value('id');
        if ($owner !== null) {
            DB::table('users')->where('id', $owner)->update(['role' => 'owner']);
        }
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable();
            $table->string('subject_type', 32)->nullable();
            $table->index(['action', 'id'], 'audit_action_history');
        });
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'curator')->update(['is_admin' => false]);
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_action_history');
            $table->dropColumn(['request_id', 'subject_type']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'access_version', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
