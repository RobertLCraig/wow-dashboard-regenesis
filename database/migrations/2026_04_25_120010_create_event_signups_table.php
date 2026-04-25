<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raid_event_id')->constrained('raid_events')->cascadeOnDelete();
            // Raid-Helper's per-signup ID (numeric position or hash; varies
            // by template). Used to upsert from webhook payloads.
            $table->string('raidhelper_signup_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('name');

            $table->string('class_name')->nullable();
            $table->string('spec_name')->nullable();
            $table->string('spec2_name')->nullable();
            $table->string('spec3_name')->nullable();

            // Free-form so we can mirror whatever Raid-Helper's template
            // emits (Tank/Healer/DPS, role/spec hybrids, "Bench", etc.).
            $table->string('role')->nullable();
            // 'signed', 'absent', 'late', 'tentative', 'declined', etc.
            $table->string('status', 32)->index();

            $table->unsignedInteger('position')->nullable();
            $table->boolean('is_fake')->default(false);
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamps();

            $table->unique(['raid_event_id', 'raidhelper_signup_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_signups');
    }
};
