<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            // The few volatile fields we keep per-snapshot. The full per-
            // member raw payload lives in raw_json for replay/debugging.
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedTinyInteger('rank_index')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->boolean('recommend_promote')->default(false);
            $table->boolean('recommend_demote')->default(false);
            $table->boolean('recommend_kick')->default(false);
            $table->json('raw_json')->nullable();

            // Wowaudit hook columns. Nullable today, populated by a future
            // WowauditDataSource without requiring a schema migration.
            $table->unsignedSmallInteger('ilvl')->nullable();
            $table->json('vault_progress_json')->nullable();
            $table->unsignedSmallInteger('mplus_keystone')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_snapshots');
    }
};
