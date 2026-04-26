<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character per-fight performance row. WCL exposes ranking + spec
 * + class via the rankings/table queries; we store the headline
 * numbers (DPS or HPS, parse %, ilvl) so widgets can show "who pulled
 * which weight on which fight" without re-querying WCL.
 *
 * actor_name is unmatched-by-default (a free-form character name from
 * the WCL report); a future job can resolve it to members.id once we
 * trust the matching, but the audit trail stays useful even without
 * a member link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wcl_actor_parses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wcl_fight_id')->constrained('wcl_fights')->cascadeOnDelete();
            // Optional link to our local member row when we can confidently
            // match by name + (eventually) realm. Null is the common case
            // until a follow-up matcher runs.
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->string('actor_name');
            $table->string('actor_class', 32)->nullable();
            $table->string('actor_spec', 32)->nullable();
            // 'tank' | 'healer' | 'dps' inferred from the WCL spec/role.
            $table->string('role', 16)->nullable()->index();

            // Headline metric for this fight + role. DPS for damage,
            // HPS for healers. Stored as decimal so we can sort.
            $table->decimal('metric_per_second', 12, 1)->nullable();
            // Parse percentile (0-100). Null when WCL hasn't ranked yet.
            $table->unsignedTinyInteger('parse_percentile')->nullable()->index();
            // Bracket parse (vs same ilvl). Useful for noting an ilvl
            // disadvantage on the "raw" parse.
            $table->unsignedTinyInteger('bracket_percentile')->nullable();
            $table->unsignedSmallInteger('item_level')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['wcl_fight_id', 'actor_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wcl_actor_parses');
    }
};
