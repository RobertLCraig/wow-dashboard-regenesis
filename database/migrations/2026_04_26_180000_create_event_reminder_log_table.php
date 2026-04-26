<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (event, minutes_before) pair the dispatcher has fired.
 * Drives the "did we already ping for this offset?" check so the
 * 5-minute scheduler tick doesn't re-post the same reminder.
 *
 * No FK to raid_events because we want the log row to survive a
 * soft-delete of the event (keeps the audit trail clean if an event
 * is later cancelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminder_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raid_event_id')->index();
            $table->unsignedSmallInteger('minutes_before');
            $table->timestamp('posted_at');
            $table->unsignedTinyInteger('webhook_count')->default(0);
            $table->timestamps();

            $table->unique(['raid_event_id', 'minutes_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminder_log');
    }
};
