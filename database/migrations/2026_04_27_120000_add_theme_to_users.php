<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user accent-colour theme. Orthogonal to display_mode (which
 * controls layout + typography for accessibility); theme just swaps
 * the accent colour from Discord blurple to phoenix red. Stored as
 * varchar so we can add high-contrast / mono / seasonal themes
 * without a schema change.
 *
 * v1 values: 'discord' (default) | 'phoenix'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 32)->default('discord')->after('display_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
