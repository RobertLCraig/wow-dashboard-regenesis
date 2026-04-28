<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character M+ run log.
 *
 * One row per completed Mythic+ keystone for a member, deduped by
 * (member_id, completed_at). Populated from Raider.IO's run-bearing
 * profile fields (recent, weekly best, previous weekly best, season
 * best, alternate). The RIO importer upserts on every pull and the
 * unique constraint folds duplicate sightings of the same run across
 * different fields and across snapshots into a single row.
 *
 * The combined coverage of those fields, sampled every 3 hours, gets
 * us close to "every key" for typical players. Where it falls short
 * (heavy pushers running >10 keys in a 3h window, depleted keys that
 * RIO discards) is documented in
 * docs/planning/mplus-run-tracker-addon.md as the Path B upgrade.
 *
 *   completed_at        Authoritative dedupe key alongside member_id.
 *                       RIO returns a ms-precision ISO timestamp, so
 *                       same-second collisions across dungeons are
 *                       not realistic.
 *   mythic_level        Keystone level (+X). The signal raid leaders
 *                       care about most after activity.
 *   dungeon_id          RIO's map_challenge_mode_id. Stable across
 *                       seasons, survives dungeon renames.
 *   dungeon_short_name  Display token ("AD", "FALL"). Cheap to filter
 *                       by in the dashboard without joining a lookup.
 *   num_keystone_upgrades  0 = untimed/depleted-completion, 1-3 = on-
 *                          time +X. Drives the "is timed" derivation
 *                          and the chip colour on the heatmap.
 *   source              Which RIO field sourced the row first. Lets
 *                       us reason about coverage gaps; not used for
 *                       dedupe (`completed_at` is the source of truth).
 *   first_seen_at /     Sampling provenance. first_seen_at = when we
 *   last_seen_at        first persisted this run (day-of-completion
 *                       for new runs, backfill date for historic).
 *                       last_seen_at = the most recent pull where the
 *                       run still appeared in any RIO field, useful
 *                       for catching "RIO retroactively dropped this".
 *   first_seen_snapshot_id  FK to the snapshot row that introduced
 *                           the run. Cascade-null if that snapshot
 *                           gets pruned later.
 *   raw_json            The original RIO run dict. Future-proofs us
 *                       against fields we don't currently extract
 *                       (affixes detail, party comp once RIO exposes
 *                       it, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_mplus_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            $table->timestamp('completed_at');
            $table->unsignedSmallInteger('mythic_level');
            $table->unsignedInteger('dungeon_id')->nullable();
            $table->string('dungeon_short_name', 16)->nullable();
            $table->string('dungeon_name', 64)->nullable();

            $table->unsignedInteger('clear_time_ms')->nullable();
            $table->unsignedInteger('par_time_ms')->nullable();
            $table->unsignedTinyInteger('num_keystone_upgrades')->default(0);

            $table->decimal('score', 7, 1)->nullable();
            $table->json('affixes')->nullable();
            $table->string('season_slug', 32)->nullable();

            $table->string('source', 24)->index();
            $table->foreignId('first_seen_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->json('raw_json')->nullable();

            $table->timestamps();

            $table->unique(['member_id', 'completed_at']);
            $table->index(['member_id', 'mythic_level']);
            $table->index(['member_id', 'season_slug']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_mplus_runs');
    }
};
