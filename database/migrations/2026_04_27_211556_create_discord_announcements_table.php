<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_announcements', function (Blueprint $table) {
            $table->id();
            // Discord snowflake of the source message; treated as the
            // PK across pulls so a re-run is idempotent.
            $table->string('discord_message_id')->unique();
            $table->string('guild_id')->nullable();
            $table->string('channel_id');
            $table->string('author_username');
            $table->string('author_id')->nullable();
            $table->text('content');
            $table->timestamp('posted_at')->index();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_announcements');
    }
};
