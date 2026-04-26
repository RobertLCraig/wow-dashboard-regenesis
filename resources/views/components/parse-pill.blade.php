@props(['percentile'])
@php
    /**
     * WCL parse-percentile pill, coloured to the standard WCL tiers:
     *   100   = artifact (gold/orange)
     *   95-99 = legendary (orange)
     *   75-94 = epic (purple)
     *   50-74 = rare (blue)
     *   25-49 = uncommon (green)
     *   1-24  = common (grey)
     *   null  = no ranking yet (dim placeholder)
     */
    $p = $percentile;
    if ($p === null) {
        $tone = 'border-line text-muted/60';
        $label = '-';
    } else {
        $p = (int) $p;
        $tone = match (true) {
            $p >= 100 => 'border-orange-400/70 text-orange-300 bg-orange-950/30',
            $p >= 95  => 'border-orange-500/60 text-orange-300',
            $p >= 75  => 'border-purple-500/60 text-purple-300',
            $p >= 50  => 'border-sky-500/60   text-sky-300',
            $p >= 25  => 'border-emerald-500/60 text-emerald-300',
            default   => 'border-line text-muted',
        };
        $label = (string) $p;
    }
@endphp
<span {{ $attributes->merge(['class' => "inline-block text-[10px] font-mono font-semibold uppercase tracking-wider border rounded px-1.5 py-0.5 leading-none {$tone}"]) }}>
    {{ $label }}
</span>
