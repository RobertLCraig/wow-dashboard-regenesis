<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character raid progression from
 * /profile/wow/character/.../encounters/raids.
 *
 * The payload is hierarchical: expansions[] -> instances[] -> modes[]
 * (one per difficulty: LFR / Normal / Heroic / Mythic) -> progress
 * with completed_count, total_count, and a per-encounter array
 * including last_kill_timestamp. Storing the whole tree as JSON lets
 * the dashboard render any view we want (AOTC/CE detection, last-kill
 * recency, per-team progression) without write-time normalisation.
 *
 * Unlike wowaudit's progression view, this does not require members
 * to opt in - the endpoint covers anyone in the guild roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_raid_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            $table->json('expansions')->nullable();

            $table->timestamps();

            $table->unique(['snapshot_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_raid_snapshots');
    }
};
