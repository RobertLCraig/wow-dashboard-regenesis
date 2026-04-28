<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid roster model: Blizzard's guild roster endpoint becomes the
 * authoritative "who is in the guild" signal, while GRM stays the
 * source for everything Blizzard doesn't expose (custom notes, alt
 * linkage, join dates, recommend_* flags, the leaver/banned ledger).
 *
 *   blizzard_character_id   Stable per-realm character ID. Survives
 *                           renames, transfers update it. Strongest
 *                           join key once populated; until then the
 *                           importer matches on (guild_key, name).
 *   realm_slug              Canonical Blizzard slug, e.g. "silvermoon"
 *                           or "twisting-nether". Distinct from the
 *                           existing `realm` column (proper-cased name
 *                           backfilled from RIO). Cheaper to use as
 *                           the URL segment for every other Blizzard
 *                           endpoint than re-deriving from RIO's map.
 *   last_blizzard_seen_at   Updated whenever the guild roster pull
 *                           finds this character. GRM's equivalent
 *                           lives in `last_seen_at`. Comparing the two
 *                           tells officers when a source has lapsed.
 *   is_valid_at_blizzard    Mirrors the /status endpoint's is_valid
 *                           bool. False means the character was
 *                           deleted or transferred away. Nullable so
 *                           "never checked" is distinguishable from
 *                           "checked and gone".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedBigInteger('blizzard_character_id')->nullable()->after('guid')->index();
            $table->string('realm_slug')->nullable()->after('realm')->index();
            $table->timestamp('last_blizzard_seen_at')->nullable()->after('last_seen_at')->index();
            $table->boolean('is_valid_at_blizzard')->nullable()->after('last_blizzard_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn([
                'blizzard_character_id',
                'realm_slug',
                'last_blizzard_seen_at',
                'is_valid_at_blizzard',
            ]);
        });
    }
};
