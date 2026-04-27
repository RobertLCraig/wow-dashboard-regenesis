<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user dashboard widget order. Stored as a JSON list of widget
 * keys (e.g. ["action-queue", "upcoming-events", ...]). Null = use
 * the project's default order. Unknown keys in the saved layout
 * are skipped on render; widgets not in the saved layout are
 * appended in default order so newly-added widgets appear without
 * the user having to re-edit their layout.
 *
 * Stored on users (not its own table) because the layout is small
 * and per-user; no need for the join. Cast to array on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dashboard_layout')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_layout');
        });
    }
};
