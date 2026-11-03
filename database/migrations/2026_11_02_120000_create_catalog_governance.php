<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_change_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16);
            $table->char('plan_hash', 64);
            $table->json('plan');
            $table->json('snapshot');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('member_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('proposed_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16);
            $table->string('summary', 240);
            $table->text('note')->nullable();
            $table->json('payload');
            $table->json('catalog_snapshot');
            $table->unsignedBigInteger('revision')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->foreignUuid('accepted_batch_id')->nullable()->constrained('catalog_change_batches')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['proposed_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_proposals');
        Schema::dropIfExists('catalog_change_batches');
    }
};
