@php
    use App\Support\Wowhead;

    /**
     * @var array{
     *   class:string, spec:string, profile_name:string,
     *   profile_gear_ilvl:?float, source:string,
     *   source_captured_at:?\Carbon\CarbonInterface,
     *   slots:array<string, array<string,mixed>>,
     *   consumables:array<string,string>,
     * } $comparison
     */

    /** Pretty slot labels in the same order as the in-game paper doll. */
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
            'different'      => 'wrong',
            'wrong'          => 'wrong',
            'count_mismatch' => 'partial',
            default          => $status,
        };
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

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted bg-bg/50">
                <tr>
                    <th class="px-3 py-2 text-left">Slot</th>
                    <th class="px-3 py-2 text-left">Equipped</th>
                    <th class="px-3 py-2 text-left">BiS</th>
                    <th class="px-3 py-2 text-center">Enchant</th>
                    <th class="px-3 py-2 text-center">Gems</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slotOrder as $slotKey => $slotLabel)
                    @php $row = $comparison['slots'][$slotKey] ?? null; @endphp
                    @if ($row === null) @continue @endif
                    <tr class="border-t border-line/60">
                        <td class="px-3 py-2 text-muted">{{ $slotLabel }}</td>
                        <td class="px-3 py-2">
                            @if ($row['actual_item_id'])
                                <a href="{{ Wowhead::url($row['actual_item_id']) }}"
                                   data-wowhead="{{ Wowhead::dataAttr(
                                       $row['actual_item_id'],
                                       gemIds: $row['actual_gem_ids'] ?? [],
                                       enchantId: ($row['actual_enchant_ids'][0] ?? null),
                                   ) }}"
                                   target="_blank" rel="noopener"
                                   class="text-xs hover:underline">
                                    {{ Wowhead::formatItemName($row['actual_item_name']) ?? '#' . $row['actual_item_id'] }}
                                </a>
                            @else
                                <span class="text-muted text-xs italic">empty</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @if ($row['bis_item_id'])
                                <a href="{{ Wowhead::url($row['bis_item_id']) }}"
                                   data-wowhead="{{ Wowhead::dataAttr(
                                       $row['bis_item_id'],
                                       gemIds: $row['bis_gem_ids'] ?? [],
                                       enchantId: $row['bis_enchant_id'] ?? null,
                                   ) }}"
                                   target="_blank" rel="noopener"
                                   class="text-xs hover:underline {{ $row['item_match'] ? 'text-emerald-400' : 'text-muted' }}">
                                    {{ Wowhead::formatItemName($row['bis_item_name']) ?? '#' . $row['bis_item_id'] }}
                                </a>
                            @else
                                <span class="text-muted text-xs">-</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center text-xs uppercase tracking-wider {{ $tone($row['enchant_status']) }}">
                            {{ $label($row['enchant_status']) }}
                        </td>
                        <td class="px-3 py-2 text-center text-xs uppercase tracking-wider {{ $tone($row['gems_status']) }}">
                            {{ $label($row['gems_status']) }}
                            @if (! empty($row['bis_gem_ids']) && $row['gems_status'] !== 'matched')
                                <span class="text-muted normal-case ml-1">
                                    ({{ count($row['actual_gem_ids']) }}/{{ count($row['bis_gem_ids']) }})
                                </span>
                            @endif
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
