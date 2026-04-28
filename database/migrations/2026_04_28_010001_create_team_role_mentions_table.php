<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-team Discord role pings. One row per (team, role) pair: the
 * team's slug from config('raidhelper.teams') and a discord_roles.id
 * the team should mention on every event posted to its channel.
 *
 * `position` orders the roles within a team for stable rendering of
 * the "Will ping: @A, @B" preview. The unique (team_slug, discord_role_id)
 * pair prevents duplicates so the admin form's checkbox grid maps 1:1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_role_mentions', function (Blueprint $table) {
            $table->id();
            $table->string('team_slug', 32);
            $table->foreignId('discord_role_id')->constrained('discord_roles')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['team_slug', 'discord_role_id']);
            $table->index('team_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_role_mentions');
    }
};
