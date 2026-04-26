<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Headline data for each WCL report (one raid night). Fights + per-
 * character parses live in their own tables (wcl_fights, wcl_parses)
 * shipped in a follow-up so the foundation can land independently.
 *
 * `code` is WCL's stable per-report identifier (5-12 char base32-ish);
 * unique on its own. We keep the raw GraphQL payload alongside the
 * normalized columns for replay + future field surfacing without a
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wcl_reports', function (Blueprint $table) {
            $table->id();
            $table->string('guild_key')->index();
            $table->string('code', 32)->unique();
            $table->string('title');
            $table->timestamp('start_time')->index();
            $table->timestamp('end_time')->nullable();
            // Zone is the raid instance (e.g. "Manaforge Omega"). Cheap
            // to filter parses by tier later.
            $table->unsignedInteger('zone_id')->nullable()->index();
            $table->string('zone_name')->nullable();
            $table->string('owner_name')->nullable();
            // Full GraphQL payload for the report row. Lets us add new
            // surfaced columns without re-fetching from WCL.
            $table->json('raw_json')->nullable();
            // When we last refreshed this report from WCL. The reports
            // list is replayable; this just helps drive widget freshness.
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wcl_reports');
    }
};
