@php
    use App\Support\Wowhead;
    use App\Support\WowDictionary;

    /**
     * @var array{
     *   class:string, spec:string, profile_name:string,
     *   profile_gear_ilvl:?float, source:string,
     *   source_captured_at:?\Carbon\CarbonInterface,
     *   slots:array<string, array<string,mixed>>,
     *   consumables:array<string,string>,
     * } $comparison
     */

    $dict = app(WowDictionary::class);

    $slotOrder = [
        'head' => 'Head', 'neck' => 'Neck', 'shoulders' => 'Shoulders',
        'back' => 'Back', 'chest' => 'Chest', 'wrists' => 'Wrists',
        'hands' => 'Hands', 'waist' => 'Waist', 'legs' => 'Legs', 'feet' => 'Feet',
        'finger1' => 'Ring 1', 'finger2' => 'Ring 2',
        'trinket1' => 'Trinket 1', 'trinket2' => 'Trinket 2',
        'main_hand' => 'Main Hand', 'off_hand' => 'Off Hand',
    ];

    /** Tone classes per status. matched/none_required = green; missing = red;
     *  different/wrong/count_mismatch = amber; extra (BiS expects none but
     *  player has one) is treated as fine. */
    $tone = function (string $status): string {
        return match ($status) {
            'matched', 'none_required', 'extra' => 'text-emerald-400',
            'missing'                            => 'text-red-400',
            'different', 'wrong', 'count_mismatch' => 'text-amber-400',
            default                              => 'text-muted',
        };
    };

    $label = function (string $status): string {
        return match ($status) {
            'matched'        => 'OK',
            'none_required'  => '-',
            'extra'          => 'extra',
            'missing'        => 'MISSING',
            'different', 'wrong' => 'wrong',
            'count_mismatch' => 'partial',
            default          => $status,
        };
    };

    /** Friendly name for an enchant id; falls back to "Enchant #ID" so the
     *  row still reads as something the officer can paste / google. */
    $enchantName = function (?int $id) use ($dict): string {
        if ($id === null) {
            return '';
        }
        $entry = $dict->enchant($id);
        return $entry['name'] ?? "Enchant #{$id}";
    };

    /** Friendly name for a gem id, same fallback shape as enchants. */
    $gemName = function (int $id) use ($dict): string {
        $entry = $dict->gem($id);
        return $entry['name'] ?? "Gem #{$id}";
    };

    /** Wowhead URL for a single enchant. Prefers spell= when we have one
     *  cached (gives a richer tooltip), otherwise the item-enchantment
     *  page works for any enchant_id. */
    $enchantUrl = function (int $id) use ($dict): string {
        $entry = $dict->enchant($id);
        $spellId = is_int($entry['spell_id'] ?? null) ? $entry['spell_id'] : null;
        return $spellId
            ? "https://www.wowhead.com/spell={$spellId}"
            : "https://www.wowhead.com/item-enchantment/{$id}";
    };
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden mb-6">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between flex-wrap gap-2">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wider">BiS comparison</h2>
            <p class="text-xs text-muted mt-0.5">
                {{ ucwords(str_replace('_', ' ', $comparison['spec'])) }}
                {{ ucwords(str_replace('_', ' ', $comparison['class'])) }}
                vs
                <span class="font-mono">{{ $comparison['profile_name'] }}</span>
                @if ($comparison['profile_gear_ilvl'] !== null)
                    (BiS ilvl {{ number_format($comparison['profile_gear_ilvl'], 1) }})
                @endif
            </p>
        </div>
        <span class="text-[10px] text-muted">
            actual gear via {{ $comparison['source'] }}
            {{ $comparison['source_captured_at']?->diffForHumans() ?? '' }}
        </span>
    </header>

    @php $freshness = $dict->freshness(); @endphp
    @if ($freshness['missing_names'] > 0 || ($freshness['updated_at'] && $freshness['updated_at']->diffInDays() > 60))
        <div class="px-4 py-2 bg-amber-500/10 border-b border-amber-500/30 text-xs text-amber-200 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="font-semibold uppercase tracking-wider">Dictionary stale</span>
            @if ($freshness['updated_at'])
                <span>Updated {{ $freshness['updated_at']->diffForHumans() }}{{ $freshness['patch'] ? ' (' . $freshness['patch'] . ')' : '' }}.</span>
            @endif
            @if ($freshness['missing_names'] > 0)
                <span>{{ $freshness['missing_names'] }} of {{ $freshness['total_ids'] }} IDs missing names.</span>
            @endif
            <span class="text-muted">Refresh after each patch: <span class="font-mono">php artisan wow:dictionary:scan</span></span>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted bg-bg/50">
                <tr>
                    <th class="px-3 py-2 text-left">Slot</th>
                    <th class="px-3 py-2 text-left">Item</th>
                    <th class="px-3 py-2 text-left">Enchant</th>
                    <th class="px-3 py-2 text-left">Gems</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slotOrder as $slotKey => $slotLabel)
                    @php $row = $comparison['slots'][$slotKey] ?? null; @endphp
                    @if ($row === null) @continue @endif

                    @php
                        $enchantStatus = $row['enchant_status'] ?? 'none_required';
                        $gemsStatus = $row['gems_status'] ?? 'none_required';

                        $actualEnchantId = $row['actual_enchant_ids'][0] ?? null;
                        $bisEnchantId = $row['bis_enchant_id'] ?? null;
                        $actualGemIds = $row['actual_gem_ids'] ?? [];
                        $bisGemIds = $row['bis_gem_ids'] ?? [];
                    @endphp

                    <tr class="border-t border-line/60 align-top">
                        <td class="px-3 py-2 text-muted whitespace-nowrap">{{ $slotLabel }}</td>

                        {{-- Item: equipped + BiS, both selectable, BiS muted when matched --}}
                        <td class="px-3 py-2">
                            <div class="space-y-0.5">
                                @if ($row['actual_item_id'])
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-[10px] uppercase tracking-wider text-muted w-12 shrink-0">Have</span>
                                        <a href="{{ Wowhead::url($row['actual_item_id']) }}"
                                           data-wowhead="{{ Wowhead::dataAttr(
                                               $row['actual_item_id'],
                                               gemIds: $actualGemIds,
                                               enchantId: $actualEnchantId,
                                           ) }}"
                                           target="_blank" rel="noopener"
                                           class="text-xs hover:underline {{ $row['item_match'] ? 'text-emerald-400' : 'text-ink' }}">
                                            {{ Wowhead::formatItemName($row['actual_item_name']) ?? '#' . $row['actual_item_id'] }}
                                        </a>
                                    </div>
                                @else
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-[10px] uppercase tracking-wider text-muted w-12 shrink-0">Have</span>
                                        <span class="text-muted text-xs italic">empty</span>
                                    </div>
                                @endif

                                @if ($row['bis_item_id'] && ! $row['item_match'])
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-[10px] uppercase tracking-wider text-muted w-12 shrink-0">BiS</span>
                                        <a href="{{ Wowhead::url($row['bis_item_id']) }}"
                                           data-wowhead="{{ Wowhead::dataAttr(
                                               $row['bis_item_id'],
                                               gemIds: $bisGemIds,
                                               enchantId: $bisEnchantId,
                                           ) }}"
                                           target="_blank" rel="noopener"
                                           class="text-xs hover:underline text-muted">
                                            {{ Wowhead::formatItemName($row['bis_item_name']) ?? '#' . $row['bis_item_id'] }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </td>

                        {{-- Enchant: name as plain text, small status pill, BiS shown when not matched --}}
                        <td class="px-3 py-2">
                            <div class="space-y-0.5">
                                @if ($enchantStatus === 'none_required')
                                    <span class="text-muted text-xs">-</span>
                                @else
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <span class="text-[10px] uppercase tracking-wider w-12 shrink-0 {{ $tone($enchantStatus) }}">{{ $label($enchantStatus) }}</span>
                                        @if ($actualEnchantId)
                                            <span class="text-xs text-ink select-text">{{ $enchantName($actualEnchantId) }}</span>
                                            <a href="{{ $enchantUrl($actualEnchantId) }}"
                                               target="_blank" rel="noopener"
                                               class="text-[10px] text-muted hover:text-ink"
                                               aria-label="View enchant on Wowhead"
                                               title="Wowhead">↗</a>
                                        @else
                                            <span class="text-xs text-muted italic">none</span>
                                        @endif
                                    </div>
                                    @if ($bisEnchantId && $enchantStatus !== 'matched' && $enchantStatus !== 'extra')
                                        <div class="flex items-baseline gap-2 flex-wrap">
                                            <span class="text-[10px] uppercase tracking-wider text-muted w-12 shrink-0">BiS</span>
                                            <span class="text-xs text-muted select-text">{{ $enchantName($bisEnchantId) }}</span>
                                            <a href="{{ $enchantUrl($bisEnchantId) }}"
                                               target="_blank" rel="noopener"
                                               class="text-[10px] text-muted hover:text-ink"
                                               aria-label="View BiS enchant on Wowhead"
                                               title="Wowhead">↗</a>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </td>

                        {{-- Gems: list of names, status, BiS shown when not matched --}}
                        <td class="px-3 py-2">
                            <div class="space-y-0.5">
                                @if ($gemsStatus === 'none_required')
                                    <span class="text-muted text-xs">-</span>
                                @else
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <span class="text-[10px] uppercase tracking-wider w-12 shrink-0 {{ $tone($gemsStatus) }}">
                                            {{ $label($gemsStatus) }}
                                            @if (! empty($bisGemIds) && $gemsStatus !== 'matched')
                                                <span class="text-muted normal-case">({{ count($actualGemIds) }}/{{ count($bisGemIds) }})</span>
                                            @endif
                                        </span>
                                        @if (! empty($actualGemIds))
                                            <span class="text-xs text-ink select-text">
                                                @foreach ($actualGemIds as $i => $gid)
                                                    {{ $gemName($gid) }}<a href="{{ Wowhead::url($gid) }}" target="_blank" rel="noopener" class="text-[10px] text-muted hover:text-ink ml-0.5" title="Wowhead">↗</a>{{ $i < count($actualGemIds) - 1 ? ', ' : '' }}
                                                @endforeach
                                            </span>
                                        @else
                                            <span class="text-xs text-muted italic">none</span>
                                        @endif
                                    </div>
                                    @if (! empty($bisGemIds) && $gemsStatus !== 'matched' && $gemsStatus !== 'extra')
                                        <div class="flex items-baseline gap-2 flex-wrap">
                                            <span class="text-[10px] uppercase tracking-wider text-muted w-12 shrink-0">BiS</span>
                                            <span class="text-xs text-muted select-text">
                                                @foreach ($bisGemIds as $i => $gid)
                                                    {{ $gemName($gid) }}<a href="{{ Wowhead::url($gid) }}" target="_blank" rel="noopener" class="text-[10px] text-muted hover:text-ink ml-0.5" title="Wowhead">↗</a>{{ $i < count($bisGemIds) - 1 ? ', ' : '' }}
                                                @endforeach
                                            </span>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (! empty($comparison['consumables']))
        <footer class="px-4 py-3 border-t border-line text-xs text-muted">
            <span class="uppercase tracking-wider">Recommended:</span>
            @php
                $consumablePairs = [
                    'flask' => 'Flask',
                    'food' => 'Food',
                    'potion' => 'Potion',
                    'augmentation' => 'Aug rune',
                    'temporary_enchant_main_hand' => 'MH oil',
                    'temporary_enchant_off_hand' => 'OH oil',
                ];
            @endphp
            @foreach ($consumablePairs as $key => $title)
                @if (! empty($comparison['consumables'][$key]))
                    <span class="ml-2">{{ $title }}: <span class="font-mono">{{ str_replace('_', ' ', $comparison['consumables'][$key]) }}</span></span>
                @endif
            @endforeach
        </footer>
    @endif
</section>
