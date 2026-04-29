<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discord-to-character linkage. Many members can share the same
 * discord_user_id (one Discord user owns a main + N alts), so this is
 * a plain indexed column, not unique.
 *
 * Officers fill these in by hand for now via the roster row's "Discord"
 * action. A later auto-resolver will translate a username-only entry
 * into the snowflake by querying Discord's guild members search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Discord snowflake. String because snowflakes overflow a
            // 64-bit signed integer in some languages and Discord's own
            // SDKs treat them as strings everywhere. Indexed because
            // "find every character owned by this Discord user" is the
            // primary read pattern.
            $table->string('discord_user_id')->nullable()->index()->after('alt_group_label');
            // Username snapshot at link time. Discord usernames are no
            // longer immutable (the 2023 username change), so this is
            // for display, not for matching.
            $table->string('discord_username')->nullable()->after('discord_user_id');
            // Where the link came from: 'manual' (officer typed it),
            // 'recruit_form' (auto-matched from new-recruits import),
            // 'self_claim' (logged-in user claimed). String not enum
            // so we can add sources without a migration.
            $table->string('discord_link_source', 32)->nullable()->after('discord_username');
            $table->timestamp('discord_linked_at')->nullable()->after('discord_link_source');
            // Officer who set the link, for audit. Nullable because
            // self-claim and recruit-form imports won't have an officer
            // attached.
            $table->foreignId('discord_linked_by_user_id')->nullable()
                ->after('discord_linked_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['discord_linked_by_user_id']);
            $table->dropColumn([
                'discord_user_id',
                'discord_username',
                'discord_link_source',
                'discord_linked_at',
                'discord_linked_by_user_id',
            ]);
        });
    }
};
