@props([
    'title' => null,
    'align' => 'right',
    'width' => 'w-72',
])

{{--
    Tiny inline ? button that toggles a popover with explanatory text.
    Designed for column headers and other places where x-explainer-toggle
    would be too wide a hammer. Each instance has its own Alpine scope
    so multiple popovers can coexist on the same row without their
    `open` state colliding.

    Slot content is the body of the popover. Optional title prop renders
    a bolded heading at the top of the popover (matching x-explainer-panel).
    align controls which edge the popover anchors to (right by default
    so it doesn't overflow the right side of a cell). width is a Tailwind
    width utility class.
--}}

@php
    $edge = $align === 'left' ? 'left-0' : 'right-0';
@endphp

<span x-data="{ open: false }" class="relative inline-block align-middle"
      @keydown.escape="open = false">
    <button type="button"
            @click.stop="open = !open"
            :aria-expanded="open"
            aria-label="Help"
            class="w-3.5 h-3.5 inline-flex items-center justify-center rounded-full border border-line text-muted text-[9px] font-semibold leading-none hover:text-ink hover:border-muted focus:outline-none focus:ring-1 focus:ring-accent transition-colors"
            :class="{ 'bg-accent/15 border-accent/60 text-accent hover:text-accent': open }">
        <span x-text="open ? '×' : '?'"></span>
    </button>
    <div x-show="open"
         @click.outside="open = false"
         x-cloak
         x-transition.opacity.duration.150ms
         class="absolute z-30 top-full mt-1 {{ $edge }} {{ $width }} bg-bg border border-line rounded-md p-3 text-xs text-muted normal-case font-normal tracking-normal leading-relaxed shadow-lg">
        @if ($title)
            <span class="block text-ink font-semibold mb-1">{{ $title }}</span>
        @endif
        {{ $slot }}
    </div>
</span>
