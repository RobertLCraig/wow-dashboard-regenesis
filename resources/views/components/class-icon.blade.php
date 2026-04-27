@props([
    'class' => null,        // raw value from members.class (e.g. "PALADIN", "DemonHunter")
    'size'  => 16,
])

{{-- Tiny wrapper around <x-icon kind="class"> that handles the
     case-normalisation + null check that every widget would
     otherwise repeat. Also tolerant of the messy strings Raid-Helper
     hands back on event signups (which can be a class abbreviation
     like "DH" / "DK", or a role like "Tank" / "Healer", or even a
     signup status like "Bench" / "Late") - anything not on the
     known-class whitelist renders nothing rather than 404'ing the
     class-icon image and leaking alt text into the layout. --}}

@php
    $known = [
        'deathknight', 'demonhunter', 'druid', 'evoker', 'hunter', 'mage',
        'monk', 'paladin', 'priest', 'rogue', 'shaman', 'warlock', 'warrior',
    ];
    $abbrev = [
        'dk' => 'deathknight',
        'dh' => 'demonhunter',
    ];
    $resolved = null;
    if ($class) {
        $candidate = strtolower(str_replace([' ', '_', '-'], '', $class));
        $candidate = $abbrev[$candidate] ?? $candidate;
        if (in_array($candidate, $known, true)) {
            $resolved = $candidate;
        }
    }
@endphp

@if ($resolved)
    <x-icon kind="class" :name="$resolved" :size="$size" {{ $attributes }} />
@endif
