<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_recruit_forms', function (Blueprint $table) {
            $table->id();
            // Discord snowflake of the forum thread (= the post). Treated
            // as the idempotency key across pulls.
            $table->string('discord_thread_id')->unique();
            $table->string('guild_id')->nullable();
            $table->string('channel_id');
            // Raw thread title, kept verbatim so we can re-parse if the
            // regex evolves without re-hitting Discord.
            $table->string('thread_title');
            // Parsed out of the title pattern
            // "Character: NAME | Discord: NAME". Either may be null if
            // the thread title doesn't follow the form-bot pattern (e.g.
            // pinned instructional posts) - those are filtered upstream
            // but the columns stay nullable as belt-and-braces.
            $table->string('discord_username')->nullable()->index();
            $table->string('character_name')->nullable()->index();
            // First message of the thread, which is the form bot's post.
            // Stored as JSON (the raw embed payload) so we can re-derive
            // fields if the parser changes. Nullable when the thread
            // body fetch fails or the message has no embed.
            $table->json('form_embed_raw')->nullable();
            // Normalised key->value map of form fields, snake_cased keys.
            // E.g. {"reason_for_joining":"Guild/Social","join_method":"...","more_information":"..."}.
            $table->json('form_fields')->nullable();
            $table->timestamp('posted_at')->index();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_recruit_forms');
    }
};
