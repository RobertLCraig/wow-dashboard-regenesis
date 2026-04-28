@props([
    'name' => '',
    'siblings' => [],
])
{{-- Renders a character name with the differing letters bolded when at
     least one alt-group sibling has the same ASCII-folded form (e.g.
     Ñýxx vs Ñyxx). The annotation work happens in the controller via
     NameDiff so this component stays purely presentational. --}}
@php
    $segments = \App\Support\NameDiff::annotate($name, $siblings);
@endphp
<span {{ $attributes }}>@foreach ($segments as [$char, $diff])@if ($diff)<strong class="font-bold text-amber-300 underline decoration-amber-500/60 underline-offset-2">{{ $char }}</strong>@else{{ $char }}@endif@endforeach</span>
