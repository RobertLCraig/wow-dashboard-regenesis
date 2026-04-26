<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Officer-managed mapping table that says "this in-game rank means
 * heroic team" or "this Discord role means mythic team". Edited from
 * the /admin/teams page so the ranks/roles can change without a deploy.
 *
 * Also adds a `team` column to members (derived from in-game rank) and
 * users (derived from Discord roles). Both are nullable - members on no
 * team yet, or whose rank/role hasn't been mapped, simply read as null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_mappings', function (Blueprint $table) {
            $table->id();
            // 'grm_rank' or 'discord_role'. String rather than enum so
            // adding a future source (e.g. 'wowaudit_team_id') doesn't
            // need a migration.
            $table->string('source', 32)->index();
            // The thing being mapped: rank_name for grm_rank,
            // Discord role snowflake for discord_role.
            $table->string('key');
            // Human-friendly label, mainly so officers can see which
            // Discord role a snowflake is without leaving the admin page.
            $table->string('label')->nullable();
            // 'mythic' | 'mythic_trial' | 'heroic' | 'heroic_trial' | null.
            // Null means "explicitly considered, doesn't belong to a team"
            // (e.g. the Officer rank). Absent rows mean "not yet mapped".
            $table->string('team', 32)->nullable()->index();
            // Higher priority wins when a user has multiple Discord roles
            // that resolve to different teams (typical for officers who
            // hold both Mythic Raider and Heroic Raider).
            $table->unsignedSmallInteger('priority')->default(100);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'key']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('team', 32)->nullable()->after('alt_group_label')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('team', 32)->nullable()->after('tier')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('team');
        });
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('team');
        });
        Schema::dropIfExists('team_mappings');
    }
};
