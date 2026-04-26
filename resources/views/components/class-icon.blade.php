@props([
    'class' => null,        // raw value from members.class (e.g. "PALADIN", "DemonHunter")
    'size'  => 16,
])

{{-- Tiny wrapper around <x-icon kind="class"> that handles the
     case-normalisation + null check that every widget would
     otherwise repeat. Renders nothing if class is empty so callers
     can drop it in unconditionally before a name. --}}

@if ($class)
    <x-icon kind="class" :name="strtolower($class)" :size="$size" {{ $attributes }} />
@endif
