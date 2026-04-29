<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSocialSnapshot;
use App\Models\Snapshot;
use App\Services\Blizzard\CollectionsAnalyzer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pick a collectible (mount / pet / toy) by Blizzard ID and see who
 * already has it and who doesn't, so officers can plan a social farm
 * event with confidence (no point running it if nobody actually
 * needs the drop).
 *
 * Reads the latest member_social_snapshots row per active member.
 * Pets/Mounts/Toys ids are Blizzard's playable_class-style stable ids
 * (look one up on Wowhead and read the URL).
 */
class FarmPlannerController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);

        $analyzer = new CollectionsAnalyzer();
        $rawType = (string) $request->query('type', '');
        $rawId = $request->query('id');
        $type = $analyzer->isValidType($rawType) ? $rawType : null;
        $id = is_numeric($rawId) ? (int) $rawId : null;

        $result = null;
        $tier = null;
        $capturedAt = null;

        if ($type !== null && $id !== null && $id > 0) {
            $guildKey = (string) config('grm.guild_key');

            $snapshot = Snapshot::query()
                ->where('guild_key', $guildKey)
                ->where('source', Snapshot::SOURCE_BLIZZARD_SOCIAL)
                ->latest('captured_at')
                ->first();

            if ($snapshot) {
                $capturedAt = $snapshot->captured_at;

                $members = Member::query()
                    ->forGuild($guildKey)
                    ->active()
                    ->orderBy('name')
                    ->get(['id', 'name', 'class']);

                $snapsByMember = MemberSocialSnapshot::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->whereIn('member_id', $members->pluck('id')->all())
                    ->get()
                    ->keyBy('member_id');

                $result = $analyzer->gap($members, $snapsByMember, $type, $id);
            }
        }

        return view('dashboard.farm-planner', [
            'type' => $type,
            'id' => $id,
            'result' => $result,
            'capturedAt' => $capturedAt,
            'types' => CollectionsAnalyzer::TYPES,
        ]);
    }
}
