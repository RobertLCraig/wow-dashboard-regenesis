<?php

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'raiderio.region' => 'eu',
        'raiderio.default_realm_slug' => 'silvermoon',
        'raiderio.realm_slugs' => [
            'TwistingNether' => 'twisting-nether',
        ],
    ]);
});

function makeMemberRow(string $name, ?string $realm = null): Member
{
    return Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'realm' => $realm,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

it('renders all five external links for a single-word realm member', function () {
    $m = makeMemberRow('Sheday-Silvermoon');
    $html = view('components.character-links', ['member' => $m])->render();

    expect($html)
        ->toContain('https://raider.io/characters/eu/silvermoon/Sheday')
        ->toContain('https://www.warcraftlogs.com/character/eu/silvermoon/Sheday')
        ->toContain('https://worldofwarcraft.com/en-gb/character/eu/silvermoon/Sheday')
        ->toContain('https://wowanalyzer.com/character/EU/silvermoon/Sheday')
        ->toContain('https://murlok.io/character/eu/silvermoon/Sheday');
});

it('uses the canonical realm from members.realm when set, slugified with hyphens', function () {
    $m = makeMemberRow('Foo-TwistingNether', realm: 'Twisting Nether');
    $html = view('components.character-links', ['member' => $m])->render();

    expect($html)->toContain('https://raider.io/characters/eu/twisting-nether/Foo');
});

it('falls back to the slug map when realm is null', function () {
    $m = makeMemberRow('Foo-TwistingNether');
    $html = view('components.character-links', ['member' => $m])->render();

    // Slug map applied to the collapsed realm out of the GRM key.
    expect($html)->toContain('https://raider.io/characters/eu/twisting-nether/Foo');
});

it('uppercases the region for WoW Analyzer URLs only', function () {
    $m = makeMemberRow('Sheday-Silvermoon');
    $html = view('components.character-links', ['member' => $m])->render();

    expect($html)
        ->toContain('wowanalyzer.com/character/EU/silvermoon/Sheday')
        ->toContain('raider.io/characters/eu/silvermoon/Sheday');
});

it('opens links in a new tab with rel=noopener for safety', function () {
    $m = makeMemberRow('Sheday-Silvermoon');
    $html = view('components.character-links', ['member' => $m])->render();

    expect($html)
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"');
});
