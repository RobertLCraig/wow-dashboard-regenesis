<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per character death in a WCL-recorded fight. Powers the
 * "what did people die to" widget officers use to spot wipe causes.
 *
 * One actor can die multiple times in one pull (battle rez), so the
 * unique key includes death_time_ms (offset within the report). The
 * importer clears + rewrites all rows for a fight on re-import so
 * idempotence is by-fight, not by-row.
 *
 * member_id is best-effort: WCL reports use first-name-only actor
 * names so the matcher resolves on a lowercased first-name lookup
 * against the local roster, exactly like the parses table. Null is
 * common for puggers / cross-realm trial members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wcl_deaths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wcl_fight_id')->constrained('wcl_fights')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->string('actor_name');
            $table->string('actor_class', 32)->nullable();

            // Ability that delivered the killing blow. guid + name +
            // icon are kept side by side so aggregation can be by name
            // (display) but de-dup by guid (canonical), and the icon
            // stays available for the widget without a second lookup.
            $table->unsignedBigInteger('killing_ability_id')->nullable()->index();
            $table->string('killing_ability_name')->nullable()->index();
            $table->string('killing_ability_icon')->nullable();

            // Offset within the report, in milliseconds. Matches WCL's
            // own coordinate space (which is the only thing we have
            // until paired with the parent report's start_time).
            $table->unsignedInteger('death_time_ms');

            // Headline-of-death amount + overkill, so the widget can
            // surface "one-shot vs ground-attrition" patterns later.
            $table->unsignedBigInteger('death_amount')->nullable();
            $table->unsignedBigInteger('overkill_amount')->nullable();

            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['wcl_fight_id', 'actor_name', 'death_time_ms']);
            $table->index(['wcl_fight_id', 'killing_ability_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wcl_deaths');
    }
};
