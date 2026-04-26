<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Officer-managed Discord webhook table. Each row is one webhook URL
 * tagged with its purpose ('weekly_digest', 'event_announce', etc) and
 * an optional team scope so you can have, e.g., a heroic-team
 * announce webhook and a separate mythic-team announce webhook.
 *
 * URLs are sensitive (anyone with one can post in the channel) so the
 * column stores the ciphertext; the model does the encrypt/decrypt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_webhooks', function (Blueprint $table) {
            $table->id();
            // Human-friendly label shown in the admin UI ("officer chat
            // - weekly digest", "heroic raid signups - reminders", ...).
            $table->string('label');
            // Encrypted Discord webhook URL. Variable size; text rather
            // than string because Crypt::encryptString output grows by
            // ~150 bytes over the input and Discord URLs are already
            // ~120 chars unencrypted.
            $table->text('url');
            // 'weekly_digest', 'event_announce', 'event_reminder', etc.
            // Indexed for the WebhookRouter's lookup query.
            $table->string('purpose', 32)->index();
            // Optional: scope this webhook to a single team's events
            // ('heroic', 'mythic', 'keynight'). Null = guild-wide /
            // not team-scoped (e.g. the digest goes guild-wide).
            $table->string('team_slug', 32)->nullable()->index();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_posted_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_webhooks');
    }
};
