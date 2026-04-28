<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pingable Discord roles available to Raid-Helper event creation.
 *
 * One row per role. The display `name` is the in-Discord role label
 * (used in the /quickcreate paste-fallback preview); `discord_id` is
 * the snowflake we send to Raid-Helper as part of the comma-separated
 * `mentions` string. Empty `discord_id` means the role row exists but
 * has no Discord mapping yet - rows in this state are skipped at
 * mention-build time, so a half-configured role doesn't block event
 * creation.
 *
 * Edited via /admin/discord-roles. Linked to teams via
 * team_role_mentions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('discord_id', 32)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_roles');
    }
};
