<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_stats', function (Blueprint $table) {
            $table->id();
            $table->string('guild_key')->index();
            // Snapshot timestamp of when the /attendance API was polled,
            // so we can render trend lines over time.
            $table->timestamp('captured_at')->index();

            // Filters used in the API call so multiple windows can coexist
            // (e.g. all-time vs last 30 days).
            $table->string('tag_filter')->nullable();
            $table->string('channel_filter')->nullable();
            $table->timestamp('time_filter_start')->nullable();
            $table->timestamp('time_filter_end')->nullable();

            $table->string('member_name')->index();
            $table->decimal('attendance_pct', 5, 2)->nullable();
            $table->unsignedInteger('attended_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->json('raw_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_stats');
    }
};
