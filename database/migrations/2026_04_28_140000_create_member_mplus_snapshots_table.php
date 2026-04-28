<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character M+ data from
 * /profile/wow/character/.../mythic-keystone-profile.
 *
 * Stored alongside (not replacing) the existing Raider.IO M+ feed.
 * RIO computes its score from the same Blizzard data, but having the
 * canonical source lets us cross-validate, survive RIO outages, and
 * display Blizzard's own rating/colour band where appropriate.
 *
 *   mythic_rating       Current overall mythic+ rating (sum across
 *                       all dungeons, Blizzard's own roll-up).
 *                       decimal(6,1) covers the realistic range
 *                       (0-9999.9) without floating-point drift.
 *   current_period_runs JSON of the current weekly period's best
 *                       runs (used for vault eligibility checks).
 *   seasons             JSON of the lighter seasons[] summary so we
 *                       can render lifetime progression without a
 *                       per-season fan-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_mplus_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            $table->decimal('mythic_rating', 6, 1)->nullable();
            $table->json('current_period_runs')->nullable();
            $table->json('seasons')->nullable();

            $table->timestamps();

            $table->unique(['snapshot_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_mplus_snapshots');
    }
};
