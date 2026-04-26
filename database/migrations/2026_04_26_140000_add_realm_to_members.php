<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical realm name (with spaces and apostrophes preserved, e.g.
 * "Twisting Nether"). GRM stores it collapsed inside members.name as
 * "Char-Realm", but Raider.IO and the Blizzard API both want the proper
 * realm. We backfill this whenever a successful Raider.IO profile fetch
 * comes back, then prefer it over the GRM-derived collapsed form.
 *
 * Nullable because GRM members may not have been seen on Raider.IO yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('realm')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('realm');
        });
    }
};
