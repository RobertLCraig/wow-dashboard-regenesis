<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character gear blob from /profile/wow/character/.../equipment.
 *
 * One row per member per snapshot. The whole equipped_items payload
 * lives in `pieces` JSON; analysis (missing enchants, empty sockets,
 * stat priorities) reads that on demand so we're not coupled to any
 * single expansion's enchant slot rules at write time.
 *
 * The two denormalised ilvl columns are summary stats Blizzard ships
 * directly in the equipment payload's higher levels - duplicated here
 * for cheap roster sorting/filtering without parsing the JSON.
 *
 * Snapshot.source distinguishes these from profile snapshots:
 *   'blizzard'           = profile summary (ilvl + last_login)
 *   'blizzard_equipment' = per-piece gear
 * Same snapshots table, different source, dedupe via payload_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_equipment_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            $table->unsignedSmallInteger('equipped_ilvl')->nullable();
            $table->unsignedSmallInteger('average_ilvl')->nullable();
            $table->json('pieces')->nullable();

            $table->timestamps();

            $table->unique(['snapshot_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_equipment_snapshots');
    }
};
