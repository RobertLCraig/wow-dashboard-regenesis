<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alt_groups', function (Blueprint $table) {
            $table->id();
            $table->string('guild_key')->index();
            // GRM stores alt groups under a numeric string key (e.g. "236").
            // We keep it as the human-readable label for debugging.
            $table->string('group_label')->index();
            // Self-referencing FK avoided here because members.id may not
            // yet exist. Stored as nullable unsigned bigint, enforced in
            // app layer. Index for joins.
            $table->unsignedBigInteger('main_member_id')->nullable()->index();
            $table->string('nickname')->nullable();
            $table->timestamp('time_modified')->nullable();
            $table->timestamps();

            $table->unique(['guild_key', 'group_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alt_groups');
    }
};
