<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user display mode. Drives the body class on every dashboard
 * page so a single toggle can opt the layout into high-clarity mode
 * (single column, stacked cards, big spacing, no motion). Stored as
 * varchar so we can add 'high-contrast' or other modes later without
 * a schema change.
 *
 * Values in v1: 'standard' (default) | 'high_clarity'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_mode', 32)->default('standard')->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('display_mode');
        });
    }
};
