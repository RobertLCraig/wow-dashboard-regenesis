@props(['member'])
@php
    /**
     * Inline strip of external-tool deep-links for one character.
     * Targets are the sites officers actually open after spotting a
     * name in the dashboard: parses, gear, M+ score, armory.
     *
     * Realm resolution prefers `members.realm` (canonical name backfilled
     * by the Raider.IO importer), falls back to the realm portion of the
     * GRM "Char-Realm" key, then to the configured guild realm.
     */
    use App\Services\Raiderio\RealmSlug;

    $name = explode('-', $member->name, 2)[0] ?? $member->name;

    if (! empty($member->realm)) {
        $slug = RealmSlug::slugifyCanonical($member->realm);
    } else {
        $collapsed = RealmSlug::realmFromMemberName($member->name);
        $slug = RealmSlug::slugify($collapsed);
    }

    $region = strtolower((string) config('raiderio.region', 'eu'));
    $regionUpper = strtoupper($region);

    $links = [
        ['label' => 'RIO', 'title' => 'Raider.IO',     'url' => "https://raider.io/characters/{$region}/{$slug}/{$name}"],
        ['label' => 'WCL', 'title' => 'Warcraft Logs', 'url' => "https://www.warcraftlogs.com/character/{$region}/{$slug}/{$name}"],
        ['label' => 'ARM', 'title' => 'WoW Armory',    'url' => "https://worldofwarcraft.com/en-gb/character/{$region}/{$slug}/{$name}"],
        ['label' => 'WA',  'title' => 'WoW Analyzer',  'url' => "https://wowanalyzer.com/character/{$regionUpper}/{$slug}/{$name}"],
        ['label' => 'MUR', 'title' => 'Murlok.io',     'url' => "https://murlok.io/character/{$region}/{$slug}/{$name}"],
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }}>
    @foreach ($links as $l)
        <a href="{{ $l['url'] }}" target="_blank" rel="noopener noreferrer"
           title="{{ $l['title'] }} - {{ $name }}"
           class="text-[10px] font-mono uppercase text-muted hover:text-accent border border-line hover:border-accent rounded px-1 py-0.5 leading-none transition-colors">
            {{ $l['label'] }}
        </a>
    @endforeach
</span>
