<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raid_events', function (Blueprint $table) {
            $table->id();
            // Discord message ID returned by Raid-Helper on event creation.
            // Unique because Raid-Helper itself treats message ID as the
            // event PK across its API.
            $table->string('raidhelper_event_id')->unique();
            $table->string('server_id');
            $table->string('channel_id');
            $table->string('leader_id')->nullable();
            $table->string('leader_name')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('template_id')->nullable();
            $table->string('color', 16)->nullable();

            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('closing_at')->nullable();

            // RFC 5545 stable UID for the .ics file. Generated once on
            // creation and never changed, so calendar clients keep the
            // same event identity across edits.
            $table->string('ics_uid')->unique();
            // Bumped on every PATCH so calendar clients refresh.
            $table->unsignedInteger('ics_sequence')->default(0);

            $table->json('advanced_settings_json')->nullable();
            $table->json('classes_json')->nullable();
            $table->json('roles_json')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_events');
    }
};
