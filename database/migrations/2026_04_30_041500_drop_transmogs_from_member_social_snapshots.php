<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the transmogs column. The full Blizzard transmog payload was
 * ~1.5 MB per character per pull and the UI never read it - it pushed
 * production over Hostinger's 3GB MySQL quota and 500'd every page
 * (sessions table writes denied). The column was already dropped on
 * production via raw ALTER as part of the recovery; this migration
 * gets dev / fresh installs to the same state.
 *
 * Idempotent so it works whether the column is still present (dev
 * baseline) or already gone (post-recovery production).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('member_social_snapshots', 'transmogs')) {
            Schema::table('member_social_snapshots', function (Blueprint $table) {
                $table->dropColumn('transmogs');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('member_social_snapshots', 'transmogs')) {
            Schema::table('member_social_snapshots', function (Blueprint $table) {
                $table->json('transmogs')->nullable();
            });
        }
    }
};
