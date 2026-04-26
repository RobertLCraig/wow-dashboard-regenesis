{{--
    Collapsible explanatory row toggled by <x-explainer-toggle />.
    Two variants:
      section (default) — full-width row sitting between a header and the body
      card             — inline block inside a small KPI card (top-bordered, no full-width padding)
--}}
@props(['title' => null, 'variant' => 'section'])

@php
    $base = 'text-xs text-muted leading-relaxed normal-case tracking-normal font-normal';
    $variantClasses = match ($variant) {
        'card'  => 'mt-3 pt-3 border-t border-line',
        default => 'px-4 py-3 border-b border-line bg-bg/40',
    };
@endphp

<div x-show="explain"
     x-cloak
     x-transition.opacity.duration.150ms
     class="{{ $base }} {{ $variantClasses }}">
    @if ($title)
        <span class="block text-ink font-semibold mb-1">{{ $title }}</span>
    @endif
    {{ $slot }}
</div>
