<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-report marker for "fights + parses have been backfilled". The
 * report-list importer leaves it null; the deep importer (one query
 * per report) sets it on completion. The deep importer skips reports
 * that already have it set unless force=true.
 *
 * Stored on wcl_reports rather than tracked via a join so the deep
 * importer's "what needs work" query is a single column scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wcl_reports', function (Blueprint $table) {
            $table->timestamp('fights_imported_at')->nullable()->after('captured_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('wcl_reports', function (Blueprint $table) {
            $table->dropColumn('fights_imported_at');
        });
    }
};
