<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `snapshots.source` from varchar(16) to varchar(32). The original
 * value of 16 fit `grm`, `wowaudit`, `raiderio`, `blizzard`, `blizzard_mplus`
 * (14), `blizzard_raids` (14), `blizzard_social` (15), but `blizzard_equipment`
 * (18) overflowed. Production was returning `Data too long for column 'source'`
 * on every twice-daily blizzard:pull-equipment run, silently failing the
 * entire equipment importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->string('source', 32)->default('grm')->change();
        });
    }

    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->string('source', 16)->default('grm')->change();
        });
    }
};
