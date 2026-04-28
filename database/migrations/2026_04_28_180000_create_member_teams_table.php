<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the single members.team column with a member_teams pivot so a
 * member can belong to more than one raid team (e.g. an officer who plays
 * mythic but helps in heroic), and so officers can manually override the
 * rank-derived team without losing the auto rank-mapping for everyone
 * else.
 *
 * Each pivot row is one (member, team) pair. is_override = true marks a
 * row that was set by an officer; false marks a row that was derived from
 * the in-game rank by TeamResolver. The presence of ANY override row for a
 * member means TeamResolver leaves that member's rows alone on recompute,
 * so an override fully defines the player's teams until cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // 'mythic' | 'mythic_trial' | 'heroic' | 'heroic_trial'.
            // Unconstrained string so adding a new team (e.g. 'pvp') is
            // a code-only change.
            $table->string('team', 32);
            $table->boolean('is_override')->default(false);
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'team']);
            $table->index('team');
            $table->index(['member_id', 'is_override']);
        });

        // Backfill the new pivot from the soon-to-be-dropped column.
        // Every existing non-null members.team becomes one rank-derived
        // row in member_teams.
        if (Schema::hasColumn('members', 'team')) {
            $now = now();
            DB::table('members')
                ->whereNotNull('team')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($now) {
                    $insert = [];
                    foreach ($rows as $row) {
                        $insert[] = [
                            'member_id' => $row->id,
                            'team' => $row->team,
                            'is_override' => false,
                            'set_by_user_id' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($insert) {
                        DB::table('member_teams')->insert($insert);
                    }
                });

            Schema::table('members', function (Blueprint $table) {
                $table->dropIndex(['team']);
                $table->dropColumn('team');
            });
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('team', 32)->nullable()->after('alt_group_label')->index();
        });

        // Pick one team per member as the "primary", with the same
        // precedence the dashboard uses (mythic > mythic_trial > heroic
        // > heroic_trial), so reverting doesn't lose track of the most
        // important team a member is on.
        $priority = [
            'mythic' => 4,
            'mythic_trial' => 3,
            'heroic' => 2,
            'heroic_trial' => 1,
        ];
        $byMember = DB::table('member_teams')
            ->select('member_id', 'team')
            ->get()
            ->groupBy('member_id');
        foreach ($byMember as $memberId => $rows) {
            $teams = $rows->pluck('team')->all();
            usort($teams, fn ($a, $b) => ($priority[$b] ?? 0) <=> ($priority[$a] ?? 0));
            DB::table('members')->where('id', $memberId)->update(['team' => $teams[0] ?? null]);
        }

        Schema::dropIfExists('member_teams');
    }
};
