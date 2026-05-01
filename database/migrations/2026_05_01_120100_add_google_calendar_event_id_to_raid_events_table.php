<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency anchor for Google Calendar push sync. When the per-event
 * job runs, a non-null value here means "patch this Google event"; a
 * null means "insert a new one and stash the returned id". Soft-deleted
 * RaidEvent rows trigger a delete on Google and null this column out.
 *
 * Indexed because the daily reconciliation cron looks up local rows by
 * the Google event ids it sees in the calendar to detect orphans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raid_events', function (Blueprint $table) {
            $table->string('google_calendar_event_id', 255)->nullable()->index()->after('ics_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('raid_events', function (Blueprint $table) {
            $table->dropIndex(['google_calendar_event_id']);
            $table->dropColumn('google_calendar_event_id');
        });
    }
};
