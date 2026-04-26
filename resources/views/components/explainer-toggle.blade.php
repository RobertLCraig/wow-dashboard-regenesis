{{--
    Small "?" toggle that flips a parent Alpine `explain` boolean.
    Pair with <x-explainer-panel> placed immediately after the header
    inside a wrapper that defines x-data="{ explain: false }".
--}}
<button type="button"
        @click="explain = !explain"
        :aria-expanded="explain"
        aria-label="Toggle explanation"
        class="w-4 h-4 inline-flex items-center justify-center rounded-full border border-line text-muted hover:text-ink hover:border-muted text-[10px] font-semibold leading-none cursor-pointer focus:outline-none focus:ring-1 focus:ring-accent transition-colors"
        :class="{ 'bg-accent/15 border-accent/60 text-accent hover:text-accent': explain }">
    <span x-text="explain ? '×' : '?'"></span>
</button>
