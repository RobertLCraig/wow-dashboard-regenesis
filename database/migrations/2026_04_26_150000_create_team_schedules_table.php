<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Officer-editable per-team raid schedule. Overrides the per-team
 * defaults baked into config('raidhelper.teams') so a raid lead can
 * change Heroic from Tue/Thu to Mon/Wed without a redeploy.
 *
 * One row per team slug (heroic, mythic, keynight). raid_days is a
 * JSON array of ISO weekday integers (1=Mon..7=Sun) and raid_time is
 * a HH:MM string in the configured raid timezone.
 *
 * Empty table is fine: TeamScheduleResolver falls back to the config
 * defaults so the dashboard works pre-seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('team_slug', 32)->unique();
            // JSON list of ints 1-7. Carbon::dayOfWeekIso convention.
            $table->json('raid_days');
            // HH:MM (24h) in config('raidhelper.timezone').
            $table->string('raid_time', 5);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_schedules');
    }
};
