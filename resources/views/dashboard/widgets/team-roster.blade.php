@php
    /**
     * Team roster: one row per active member on this team, sorted by
     * ilvl desc. Snapshot-derived columns (ilvl, RIO, weekly key, raid
     * summary) come from the latest raiderio MemberSnapshot; missing
     * means we haven't seen them on RIO yet, not that they're inactive.
     */
@endphp
<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">
            Roster
            <span class="text-muted text-xs font-normal normal-case ml-2">
                {{ $roster['rows']->count() }} {{ \Illuminate\Support\Str::plural('member', $roster['rows']->count()) }}
            </span>
        </h2>
        <span class="text-xs text-muted">
            @if ($roster['captured_at'])
                raider.io {{ $roster['captured_at']->diffForHumans() }}
            @else
                no raider.io data
            @endif
        </span>
    </header>
    @if ($roster['rows']->isEmpty())
        <div class="p-8 text-center text-muted text-sm">
            No members on this team yet.
            <a href="{{ route('admin.teams.index') }}" class="text-accent hover:underline">Map an in-game rank</a>
            and re-run the GRM sync.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-muted">
                        <th class="px-4 py-2 font-medium">Character</th>
                        <th class="px-2 py-2 font-medium text-right">ilvl</th>
                        <th class="px-2 py-2 font-medium text-right">RIO</th>
                        <th class="px-2 py-2 font-medium text-right">Key</th>
                        <th class="px-2 py-2 font-medium">Raid</th>
                        <th class="px-4 py-2 font-medium text-right">Links</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roster['rows'] as $row)
                        @php
                            $m = $row['member'];
                            $snap = $row['snap'];
                            $cls = 'cls-' . strtoupper($m->class ?? '');
                            $teamLabel = \App\Models\TeamMapping::teamLabel($m->team);
                            $isTrial = in_array($m->team, [
                                \App\Models\TeamMapping::TEAM_HEROIC_TRIAL,
                                \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL,
                            ], true);
                        @endphp
                        <tr class="border-t border-line">
                            <td class="px-4 py-2 truncate max-w-[260px]">
                                <span class="{{ $cls }}">{{ $m->name }}</span>
                                @if ($isTrial)
                                    <span class="ml-2 text-[10px] uppercase tracking-wider text-amber-300/80 border border-amber-700/40 rounded px-1 py-0.5">Trial</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 font-mono text-right">{{ $snap?->ilvl ?? '-' }}</td>
                            <td class="px-2 py-2 font-mono text-right">
                                {{ $snap?->mplus_score !== null ? number_format($snap->mplus_score, 0) : '-' }}
                            </td>
                            <td class="px-2 py-2 font-mono text-right">
                                {{ $snap?->mplus_keystone !== null ? '+' . $snap->mplus_keystone : '-' }}
                            </td>
                            <td class="px-2 py-2 font-mono text-xs">
                                {{ $row['raid_summary'] ?? '-' }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <x-character-links :member="$m" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
