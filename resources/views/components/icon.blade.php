@props([
    'kind',          // class | role | profession | guild-role | activity | brand
    'name',          // file basename without extension
    'size' => 24,    // px on each side; class name + width/height attrs
    'alt' => null,   // omit to use a sensible auto-label
])

@php
    $base = $kind === 'brand' ? "img/brand/{$name}" : "img/icons/{$kind}/{$name}";
    $png  = asset("{$base}.png");
    $webp = asset("{$base}.webp");

    $autoAlt = match ($kind) {
        'class'      => ucfirst($name) . ' class icon',
        'role'       => ucfirst($name) . ' role icon',
        'profession' => ucfirst($name) . ' profession',
        'guild-role' => match ($name) {
            'gm'         => 'Guild Master',
            'officer'    => 'Officer',
            'moderator'  => 'Moderator',
            'raid-lead'  => 'Raid Lead',
            default      => ucfirst($name),
        },
        'activity'   => ucfirst(str_replace('-', ' ', $name)),
        'brand'      => 'Regenesis',
        default      => ucfirst($name),
    };
    $altText = $alt ?? $autoAlt;
@endphp

<picture {{ $attributes->merge(['class' => 'inline-block align-middle shrink-0']) }}>
    <source srcset="{{ $webp }}" type="image/webp">
    <img src="{{ $png }}"
         alt="{{ $altText }}"
         width="{{ $size }}"
         height="{{ $size }}"
         loading="lazy"
         decoding="async">
</picture>
