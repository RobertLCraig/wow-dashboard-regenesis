<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('guild_key')->index();
            $table->timestamp('captured_at')->index();
            // Discriminator: which data source produced this snapshot.
            // 'grm' for the Guild_Roster_Manager addon (v1). 'wowaudit'
            // reserved for future per-character ilvl/vault/M+ data.
            $table->string('source', 16)->default('grm')->index();
            $table->string('payload_hash', 64)->index();
            $table->unsignedInteger('member_count')->nullable();
            $table->string('raw_path')->nullable();
            $table->string('grm_version')->nullable();
            $table->timestamps();

            $table->unique(['guild_key', 'source', 'payload_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
