<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hook columns for the Raider.IO source. Same nullable-additive pattern
 * we used for wowaudit so existing GRM-only rows don't have to change.
 *
 *   raid_progression_json - per-instance summary (e.g. manaforge-omega:
 *                           {summary:"8/8 H 3/8 M", normal_bosses_killed:8,
 *                            heroic_bosses_killed:8, mythic_bosses_killed:3})
 *   mplus_score           - current season Mythic+ score (the headline
 *                           number on a RIO profile). Stored as decimal
 *                           because RIO returns one decimal place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_snapshots', function (Blueprint $table) {
            $table->json('raid_progression_json')->nullable()->after('mplus_keystone');
            $table->decimal('mplus_score', 7, 1)->nullable()->after('raid_progression_json');
        });
    }

    public function down(): void
    {
        Schema::table('member_snapshots', function (Blueprint $table) {
            $table->dropColumn(['raid_progression_json', 'mplus_score']);
        });
    }
};
