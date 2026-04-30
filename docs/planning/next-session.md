# Next planning session: queued ideas

Captured 2026-04-26. Drop-in agenda for the next sit-down planning pass.

## 0. Shipped 2026-04-27 (one big session)

- **ilvl recency gate** - drop ilvl when GRM hasn't seen the char online or RIO's gear sample is older than the window; relative duration so it self-adjusts across squishes.
- **Battle.net (Blizzard) integration** - OAuth client, snapshot importer, schedule, dashboard card, on-demand sync button; multi-source roster ilvl resolver (Blizzard > Wowaudit > RIO) with per-cell `via {source}` tooltip.
- **BiS feature end-to-end** - SimC profile parser + GitHub fetcher + weekly schedule, character-page comparison section with per-slot OK / MISSING / wrong / partial badges and consumable recommendations, roster BiS-issues column with sortable count + filter chip, hero-talent-aware profile matching by gear overlap (no decoder needed), Wowhead-linked items with inline tooltips.
- **Social events hub** - read-only chronological feed mixing Raid-Helper events with computed world events (Darkmoon Faire, Trading Post resets, Love is in the Air, Children's Week, Midsummer, Brewfest, Hallow's End, Day of the Dead, Winter Veil); list view + month-grid view toggle; "Latest from Discord" announcements feed (bot-authenticated hourly poll); per-user social ICS feed (raid + world combined); public world-events ICS feed (no auth, year-ahead window).

## 1. Raw queue (verbatim)

- Review features on Guilds of WoW.
- ~~Pull in data from Warcraft Logs.~~ (shipped)
- ~~Add a sync with Battle.net option (pull data directly from Blizzard's WoW API).~~ (shipped 2026-04-27)
- Consider building our own data-collection addon.
- ~~Provide quick links to external data (Warcraft Logs, WoW Analyzer, RaiderIO, WoW Armory, etc).~~ (shipped, x-character-links component)
- ~~Pull alt groups and "recently inactive" into a new "Roster" view that is richer and more searchable, with those things as pre-made filters.~~ (shipped)
- ~~Build "kick" macros: officers click a player and get a copy-paste WoW macro that kicks them and all their alts in one go.~~ (shipped)
- Dashboard UX review: make the UI more responsive and use available screen space better. Consider letting the user pick 1-col / 2-col / 3-col / fluid responsive.
- Accessibility / high-clarity mode: GM has horizontal + vertical diplopia plus rotational tilt; build a per-user preset that strips visual noise, enforces single-column flow, increases spacing, raises contrast, and disables animation. Toggle accessible to everyone, not gated to one account. (Mode plumbing landed earlier; tightening / new-page coverage is the open work.)
- ~~Brand asset integration: phoenix logo + class / role / profession / guild-role icons~~ (largely done - components in place, icons published, theme switcher live).
- ~~Social page (events hub): not a team / cohort like Mythic / Heroic, but a guild-wide events calendar.~~ (shipped 2026-04-27)
- ~~Enchant / gem checker on the roster.~~ (shipped 2026-04-27 as the BiS issues column)
- ~~BiS gear / enchant / consumables reference~~ (shipped 2026-04-27)

## 1a. Open follow-ups from the BiS / Social work

- **Permission rework so non-officer guild members can reach Social.** Currently `OfficerOnly` middleware blocks anyone without a gm/big6/officer Discord role from the entire dashboard, so the Social page is officer-only despite being intended for everyone. Plan: add a `member` tier below `officer`, surface it in `RoleVerifier`, swap `OfficerOnly` for a route-by-route gate where Social + Roster are member-accessible while admin pages stay officer-only.
- **Multi-day event spans on the Social calendar grid.** Currently a 17-day Brewfest renders the name in 17 separate cells. Polish via CSS-grid `grid-row: span N` bars across week boundaries.
- **Discord attachments storage.** Image-only / sticker-only announcements skip the importer because we don't keep their attachments. JSON column on `discord_announcements` + render with `<img>` thumbnails when present.
- **Hero-talent matching via talent-string decode.** Voting-by-gear is good enough most of the time, but a real talent-loadout decoder would catch the remaining edge cases. Heavy work and brittle across patches.
- ~~**Wowaudit fallback for actual gear in BiS comparison.**~~ (shipped 2026-04-30 as a Blizzard-first multi-source resolver) BiS gear now flows from Blizzard `/character/equipment` -> Raider.IO -> most-recent WCL parse, with spec resolved on the same chain (Blizzard `active_spec.name` -> RIO `active_spec_name` -> WCL `actor_spec`). Wowaudit was skipped because its `_best_gear` is "best-owned" not "currently-equipped", so it's not a clean substitute. Healer specs still need manually curated `bis_profiles` rows since SimC's MID1 directory only ships DPS/tank profiles.
- **Healer BiS profiles for `bis_profiles`.** SimC won't help here. Curate ~7 specs (resto druid/shaman, holy/disc priest, holy paladin, mistweaver, preservation evoker) from Wowhead/Method/Icy Veins, seed via a one-shot artisan command. Until then the character page shows a placeholder for these specs explaining the gap.
- **Year-aware holiday lookup.** Easter-aligned (Noblegarden), Lunar New Year (Lunar Festival), and US-Thanksgiving (Pilgrim's Bounty) need a year-keyed table. Stable absolute-date holidays already shipped.

## 2. My read on the Copilot PRD

The Copilot draft is a competent generic restatement, but it's written as if this were a greenfield. It misses what's already wired into the dashboard and a few API realities. Worth keeping the structure, swapping out the parts below.

### What to keep from the Copilot draft

- The Roster overhaul section. Filters, alt-grouping, inline notes, flags, CSV export. This is the highest-leverage idea in the queue and the spec is roughly right.
- The phased ordering (foundations -> logs/roster -> officer tools -> optional addon).
- The "Quick external links" section as written (auto-generate from name+realm, compact icon strip).
- The data model sketch as a starting skeleton.

### What to push back on

1. **It ignores what's already built.** GRM ingest, wowaudit, Raid-Helper, RaiderIO, Discord OAuth + role gate are all in. The PRD treats roster, attendance, events as net-new. The next-session conversation should start by mapping the queue onto existing widgets and integrations, not by sketching from zero.
2. **Battle.net "last login" is a mirage.** The public WoW Community/Profile API does not expose a player-friendly last-login timestamp. You get `last_login_timestamp` on guild rosters at the character level (sometimes), profile `lastModifiedTimestamp`, and timestamps on individual achievements. GRM gives us better activity signal than B.net does. Worth keeping B.net for gear/itemlevel/M+/progression, but don't promise activity tracking from it.
3. **WCL doesn't need user OAuth.** The v2 GraphQL API takes a client-credentials token tied to a registered API client. No per-user flow needed for public guild logs. Strip that complication out of the plan.
4. **Custom addon is a maintenance trap for a personal tool.** GRM already collects roster + alt + note data. WCL covers combat. The remaining gap (real attendance, consumable usage, interrupts/dispels) is genuinely useful, but every addon breaks on patch day. Flag this as "evaluate after WCL lands, only if a specific gap survives."
5. **The "Member" role contradicts the project's permissions stance.** Memory says: flat-now, granular-later via Laravel Gates. Don't introduce a Member role until there's a concrete reason. GM/Big6/Officer are the audience.
6. **Hostinger constraints aren't acknowledged.** 30s PHP execution limit, no Node, no Lua, shared hosting. WCL/B.net sync has to run as queued jobs (we already have `database` queue), not synchronous controller actions. This needs to be a non-functional requirement section in the real PRD.
7. **"Custom links per guild" is over-engineering** for a single-guild dashboard.

### What I'd add

- **Hostinger NFRs** (queue-based syncs, 30s ceiling, cache aggressively, no cron beyond the once-a-minute scheduler).
- **Auth model** (Discord-only, no separate Member role for now, gates wired but flat).
- **Sync cadence table** (per-source: GRM ingest -> manual + scheduled, RIO -> hourly per recent commit, wowaudit -> on demand, WCL -> after-raid pull, B.net -> daily).
- **Cost/benefit ranking** before phasing, so the order is driven by ROI not by chronology.

## 3. Ranked: where I'd actually start

Cheap and high-value first.

### Tier 1 (do these first)

1. **Roster view consolidation.** Merge `alt-groups` and `recently-inactive` widgets into a single `/roster` page. Pre-made filters (`Inactive 7d/14d/30d/60d`, `Trial`, `Needs gear`, `Bench candidates`). Search across name/class/role/rank/ilvl/RIO/last-seen. This is plumbing rearrangement on data we already have.
2. **Quick external links.** Trivial. Add a small `<x-character-links :char="$c" />` Blade component, drop it into character cards across the dashboard. Targets: WCL, RaiderIO, Armory, WoW Analyzer (per-log, not per-character), Murlok.io. Auto-generate URLs from `name + realm + region`.
3. **Kick-macro generator.** Tiny feature, real day-to-day value. Roster row gets a "Kick + alts" action that opens a modal showing the generated `/gremove` macro(s) ready to copy. Pairs with alt-grouping logic that's already in place.
4. **High-clarity / accessibility mode.** Per-user preset that switches to single-column flow, replaces dense tables with stacked cards, bumps line-height, hardens contrast, kills animation. Designed against the GM's specific symptoms (horizontal + vertical diplopia + rotational tilt) but a first-class feature for anyone. Highest moral priority in T1; technically straightforward.
5. **Dashboard UX / responsiveness pass.** Lift the `max-w-6xl` cap, switch from hand-paired 2-col rows to a responsive grid with per-widget span hints. Pure CSS/Blade work, no new state to track. Widest visual improvement for the smallest effort. Should land before #4 so the high-clarity mode has a clean grid to opt out of.
6. **Brand asset integration.** Wire the existing phoenix logo, class icons, role badges, profession icons, and guild-role badges into the sidebar, landing, roster, action queue, and team pages. Land alongside the UX pass so the visual refresh and the asset wiring happen together.
7. **Guilds of WoW feature audit.** A 30-minute survey, write the findings into this doc. Don't spec features off it until the audit's done.

### Tier 2 (real integration work)

8. **Warcraft Logs ingest.** WCL v2 GraphQL, client-credentials token, queued sync job triggered by raid-night completion (or manual button). Store: report ID, fight roster, per-character parses (boss + spec + difficulty), deaths. New widget for parse trends.
9. **Battle.net sync.** OAuth client-credentials for guild/character data (not user OAuth). Daily queued job. Store: ilvl, M+ score, raid progression, gear pieces, last-modified timestamp. Cross-reference with GRM for alt detection via account-wide IDs (where exposed).

### Tier 3 (defer or revisit)

10. **Officer notes/flags/trial tracking.** Probably belongs to roster overhaul rather than its own phase, but flesh out after T1.
11. **Custom addon.** Park until after WCL is wired. Only revisit if a specific data gap remains and is worth maintenance burden.

## 4. Open questions for the planning session

- Are we OK starting WCL/B.net work on Hostinger's queue worker, or do we need to reconsider hosting before adding sync-heavy features?
- Should the roster overhaul become *the* main navigation entry (replacing the current widget-on-dashboard layout) or live alongside it?
- Do we want an explicit "trial pipeline" view (apply -> trial -> raider -> bench -> alumni) or just a flag on the existing roster row?
- Where does Raid-Helper attendance fit relative to WCL attendance? RH says who signed up, WCL says who actually zoned in. Officer-relevant gap.
- Does GRM's "recently inactive" signal beat B.net's `last_login_timestamp` in practice? If yes, we can downgrade B.net's role in this plan.

## 5. Guilds of WoW feature audit (T1 #3)

Done up front so the audit doesn't blocking the planning session. Source: guildsofwow.com landing/FAQ/2026 roadmap, plus mmo-champion thread.

### What GoW does that we already do

- **Roster activity overview**: weekly summary of who's around. We already cover this with `recently-inactive` + `roster-health` widgets.
- **Action queue**: GoW's "Roster activity" leans on inactivity flags much like our `action-queue` widget driven off GRM's `recommend_*` columns.
- **Officer notes**: added in early 2026 per their roadmap; we've had GRM officer notes since v1 via the `officer_note` column on `members`.
- **Event RSVPs / reminders**: covered by Raid-Helper integration. No reason to rebuild.
- **Attendance history page**: we have `attendance-stats` rolled up from RH signups, which is roughly the same shape.

### What GoW does that we don't yet

- **Weekly auto-generated guild report** (delivered as a single artifact, not a page). Could be a Markdown digest emailed to officers or posted to a Discord channel via webhook. Cheap addition once roster overhaul is done.
- **Recruitment side** (public guild listing, applications). Not in our scope. Skip.
- **Cross-guild / cross-server search**. Not in scope. Skip.
- **DM notifications for unresponsive members**. Already partially covered by Raid-Helper, but a "ping all unresponsive signups" button would be a nice add-on for raid leads.
- **Composition planning tools**. Worth investigating once we have WCL data; could overlap with Heroic/Mythic team pages.
- **Targeted roster filters baked into the UI** (the headline feature we're already planning to copy).

### Verdict

We're already at rough feature parity for everything that matters to a private dashboard. The genuine gaps are the weekly digest and the composition planner, both of which can wait. The real value GoW offers as a reference is its filtering UX (hence the Roster overhaul priority).

## 6. Tier 1 specs (concrete enough to argue about)

### 6.1 Roster page

**Route**: `GET /roster` (officer-gated, like the rest of the dashboard).

**Controller**: new `App\Http\Controllers\Dashboard\RosterController` returning `dashboard.roster` Blade view. The roster needs more data than a single widget; doing it controller-side avoids cramming the existing `DashboardController@index` further.

**Sidebar nav**: add an entry between "General" and the team pages.

```php
['route' => 'roster.index', 'label' => 'Roster', 'matches' => ['roster.*'], 'can' => 'roster.view'],
```

Add the `roster.view` ability to `AppServiceProvider` (currently flat: every officer can view). The gate name reserves room for v2 to narrow it.

**Default columns**:

| Column | Source | Notes |
|---|---|---|
| Name | `members.name` | class-coloured, links to character detail (deferred) |
| Class / Spec | `members.class`, snapshot spec | spec from latest `member_snapshots` row |
| Rank | `members.rank_name` | sortable by `rank_index` |
| ilvl | `member_snapshots.equipped_ilvl` | latest snapshot |
| RIO | `member_snapshots.raiderio_score` | from the RIO sync added in commit 6fdc306 |
| Last seen | `members.last_online_at` | uses GRM data, not B.net |
| Alt of | `members.main_member_id` | shows main name when this row is an alt |
| Flags | `recommend_*` columns | tiny pills: promote/demote/kick/special |

**Filter chips** (sit above the table, reflect `?filter=` query string):

- All
- Inactive 7d / 14d / 30d / 60d / 90d (uses `last_online_at`)
- Alts only (where `main_member_id IS NOT NULL`)
- Mains only (where `main_member_id IS NULL` and `alt_group_id IS NOT NULL`)
- Trial (depends on a future flag; placeholder for now)
- Action-queue (any `recommend_*` set)
- Banned (`status = 'banned'`)

**Search**: existing `sortableTable()` factory in [layouts/dashboard.blade.php:23-100](resources/views/layouts/dashboard.blade.php#L23-L100) handles search and column sort out of the box. Reuse it; no new JS.

**Alt grouping toggle**: a single switch above the table. When on, render each alt-group as a single row with the main, and an expand caret to reveal alts (port logic from current `alt-groups` widget). When off, render every character flat.

**CSV export**: `GET /roster.csv?filter=...` returns the filtered set. Streamed via Laravel's `response()->streamDownload()` to avoid loading 200+ rows into memory.

**Deprecation**: once Roster ships, remove the `alt-groups` and `recently-inactive` widgets from the General dashboard and link to Roster's pre-filtered URL instead. The widgets become dead code.

### 6.2 Kick-macro generator

**Problem**: officers regularly kick a player + all their alts. Today that's a manual sweep through the guild UI, character by character. Easy to miss an alt and easy to slip and kick the wrong rank.

**Approach**: dashboard generates an in-game macro string the officer copies into WoW and runs once. The dashboard never kicks anyone itself; this is a copy-paste tool.

**Mechanics**:

- WoW slash command is `/gremove <name>`. Name only, no realm. Requires the officer's in-game rank to have "Remove" permission (their problem, not ours).
- WoW macros cap at **255 characters** including newlines. Each line is `/gremove Charactername\n` so roughly 12-15 names per macro depending on name lengths.
- Long alt groups need to be split across multiple macros. Generator must compute the split, not let the officer guess.

**UI flow**:

1. Roster row's actions column gets a "Kick + alts" button (only visible if `Gate::allows('roster.kick')`, which gates everything in v1 to GM/Big6/Officer flat).
2. Click opens a modal with:
   - Header: "Kick {main name} and {n} alts?"
   - List of every character to be kicked (main highlighted, each alt class-coloured), with checkboxes so the officer can deselect specific alts (e.g. an alt that's actually a different player who got linked by mistake).
   - The generated macro(s) in a `<pre>` block, each with a "Copy" button. If multi-macro, label them "Macro 1 of 2", "Macro 2 of 2".
   - Confirmation copy: "This generates a paste-into-WoW macro. The kick happens when you run the macro in-game. The dashboard will reflect the change after the next GRM sync."
3. After clicking the primary action ("I've run the macro"), log a `MemberAction` of a new type `kick_macro_generated` per character so we have an audit trail. Don't change `members.status`; let the next GRM ingest do that.

**Generator logic** (small helper, probably `App\Support\KickMacroBuilder`):

```
buildMacros(Collection $members): array<int, string>
    lines = members.map(name => "/gremove {$name}")
    macros = []
    current = ""
    foreach line in lines:
        candidate = current === "" ? line : current . "\n" . line
        if strlen(candidate) > 255:
            macros[] = current
            current = line
        else:
            current = candidate
    if current !== "": macros[] = current
    return macros
```

255-byte cap is on the post-newline string. Guard with strlen, not mb_strlen, since `/gremove` and ASCII names are single-byte. WoW disallows non-ASCII in names anyway.

**Route**:

```
POST /roster/kick-macro
    body: { member_ids: [int, ...] }
    returns: { macros: [string, ...], characters: [{name, class}, ...] }
```

JSON endpoint, called from the modal via fetch. CSRF-protected, gated by `roster.kick`. Doesn't mutate any rows.

**Edge cases**:

- Member is already `status = 'left'` or `status = 'banned'`: skip with a warning in the modal. Macro shouldn't include them.
- Selected alts span multiple alt groups: allowed (officer might be cleaning up several mistaken links at once); generator just concatenates.
- Empty selection: button disabled.
- Single character (no alts): still works; modal just shows one name.

**Out of scope**:

- Actual in-game execution. We can't kick via API and we shouldn't try.
- Battle.net character lookups by account. The relationship comes from GRM alt-grouping, which is good enough.
- Reason / ban tracking. If banning rather than kicking, that's a separate flow that writes to `reason_banned` + `banned_at` and is worth its own spec later.

### 6.3 Dashboard UX / responsiveness pass

**Current state**:

- `<main>` in [layouts/dashboard.blade.php:231](resources/views/layouts/dashboard.blade.php#L231) is `max-w-6xl` (~1152px). On a 1440p or 4K monitor most of the screen is whitespace.
- [dashboard/index.blade.php](resources/views/dashboard/index.blade.php) hand-pairs widgets into `grid-cols-1 lg:grid-cols-2` rows. The pairings are arbitrary, the widgets don't get to declare their preferred span, and we never go beyond two columns no matter how wide the viewport.
- Mobile is fine: each row collapses to a single column under the `lg:` breakpoint already.

**The real problem**: the cap and the hard-paired rows. Not the absence of a column toggle.

**On the column-toggle question**:

Letting the user pick 1 / 2 / 3 / fluid is implementable, but it's the wrong primitive. Reasons:

- A global column count ignores per-widget preferences. `log-timeline` and `action-queue` want full width regardless of viewport. `bans` is happy at 1/3. `roster-health` is essentially a header banner. A 3-col toggle would shrink everything uniformly, which makes some widgets cramped and others awkwardly wide.
- The right answer is per-widget span hints + responsive breakpoints. The browser already knows the viewport, so smart defaults beat a setting nobody changes.
- Density (compact / cozy / comfortable) is the genuinely user-tunable axis. That's about padding and font size, not column count.

**Proposed approach** (no toggle, just smart layout):

Phase A — lift the cap. Replace `max-w-6xl` on the main container with `max-w-screen-2xl` (1536px) or fluid with horizontal padding. Pick `2xl` first; revisit if 4K users still feel cramped.

Phase B — single responsive grid with per-widget spans. Replace the hand-paired rows with one outer grid and let each widget declare its span:

```blade
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
    <div class="col-span-full">
        @include('dashboard.widgets.roster-health', ['health' => $health])
    </div>
    <div class="col-span-full xl:col-span-2">
        @include('dashboard.widgets.action-queue', ['actionQueue' => $actionQueue])
    </div>
    <div>
        @include('dashboard.widgets.bans', ['bans' => $bans])
    </div>
    {{-- ...etc --}}
</div>
```

Span recommendations per widget (first pass, expect to tweak):

| Widget | Span on lg | Span on xl | Rationale |
|---|---|---|---|
| `roster-health` | full | full | header banner, always full width |
| `action-queue` | full | 2/3 | wide table, lots of columns |
| `team-progression` | full | 2/3 | bar chart wants horizontal real estate |
| `upcoming-events` | full | 1/3 | short list, narrow is fine |
| `log-timeline` | full | full | timeline looks bad chopped narrow |
| `recently-inactive` | 1/2 | 1/3 | small table |
| `alt-groups` | 1/2 | 1/3 | small list |
| `anniversaries` | 1/2 | 1/3 | tiny list |
| `bans` | 1/2 | 1/3 | usually 0-2 entries |
| `rank-distribution` | 1/2 | 1/3 | compact pie/bar |
| `churn` | full | 2/3 | line chart, wants width |
| `vault-progress` | full | 2/3 | wide table |
| `mplus-this-week` | full | 2/3 | wide table |
| `attendance` | full | 2/3 | bar chart |

Phase C — density preference (only if the next session decides it's worth it). Adds a single `density` column to `users` (`compact|cozy|comfortable`, default `cozy`). Renders as a Tailwind class on `<body>` (`density-compact`, etc) and the existing widgets get `data-density-aware` styling with smaller padding / text under compact. Two-line PR.

**What we don't need**:

- A column-count toggle (per the argument above).
- Per-widget show/hide / drag-to-reorder. Useful eventually but adds persistence + JS state. Park it until officers complain that the defaults don't suit them.

**Apply to all dashboards**: General, Heroic, Mythic, Keynight team pages all use the same widgets. The grid pattern should be shared (a Blade component or partial would be appropriate; current pages would just slot in their controller-provided widget set).

### 6.4 High-clarity / accessibility mode

**Why this exists**

Our GM has a visual condition causing horizontal diplopia (sideways double vision), vertical diplopia (up/down double), and a rotational tilt to the right. Her own words: she especially struggles with **tables and lots of text "flowing together"**. Those are the two worst failure modes for that combination, and they describe most of our current dashboard.

This spec is built around her actual symptoms. It will incidentally help anyone with eye strain, migraines, age-related vision changes, or low-vision conditions. It is not a special-needs sub-mode: it's a first-class display preset that any user can toggle.

**Design principles**

1. **Single column, top to bottom.** Horizontal diplopia turns left-right scanning into a search problem. In high-clarity mode every dashboard collapses to one column regardless of viewport.
2. **No table looks like a table.** Tables become stacked cards: each row becomes a small panel with labeled fields rendered vertically. Dense data is preserved; the *layout* changes.
3. **Aggressive vertical separation.** Line-height 1.9, paragraph spacing doubled, large gaps between cards (24-32px minimum), heavy panel borders. Adjacent rows must not visually merge under vertical diplopia.
4. **Contrast hard, not soft.** Foreground/background contrast pushed past WCAG AAA where possible. No subtle muted text for primary content (muted is fine for tertiary metadata only).
5. **Type that survives torsion.** No italics anywhere in this mode. No font weights below 500. No condensed faces. Letter-spacing nudged up slightly.
6. **No motion.** All animations, transitions, and loading skeletons disabled. Charts render as static SVG with no enter animations. Honour `prefers-reduced-motion: reduce` automatically.
7. **Charts get a table fallback toggle.** Since pixel-precise chart reading isn't viable, every chart widget gains a "Show as list" switch that renders the same data as a labeled card stack. Defaults to list when high-clarity mode is on.
8. **Don't fight the OS.** Browser zoom, OS high-contrast, and OS magnifier are her primary tools. Our layout must survive 200% zoom without horizontal scroll. Don't use viewport units for type. Don't lock font sizes in pixels for body copy.

**Specific treatment of tables (the priority)**

The current dashboard has these table-shaped widgets: `action-queue`, `recently-inactive`, `bans`, `vault-progress`, `mplus-this-week`, `attendance`, the future Roster page.

In high-clarity mode each of these renders through a `<x-clarity-table>` Blade component that swaps the visual representation. Same data, different shape:

```blade
{{-- Default render: a normal table --}}
<x-clarity-table :rows="$inactive">
    <x-slot:columns>
        <x-clarity-column key="name" label="Name" />
        <x-clarity-column key="rank" label="Rank" />
        <x-clarity-column key="lastseen" label="Last seen" />
    </x-slot:columns>
</x-clarity-table>
```

Renders as `<table>` for normal users. Renders as a stacked card list for high-clarity users:

```
┌──────────────────────────┐
│ Name      Totemtaeven    │
│ Rank      Big6           │
│ Last seen 32 days ago    │
└──────────────────────────┘

┌──────────────────────────┐
│ Name      Otherchar      │
│ Rank      Raider         │
│ Last seen 47 days ago    │
└──────────────────────────┘
```

Big gaps between cards. Big row dividers. Labels left-aligned in a fixed 8-10ch column so values line up vertically. Each card has a heavy border so a ghost duplicate of the next card doesn't merge with this one. Sort and search controls remain identical (the existing `sortableTable()` factory just operates on the cards instead of `<tr>` elements; cards get `data-row` and `data-sort-key` exactly like rows do, so the JS doesn't change).

**Specific treatment of body text**

- Line-height jumps from default (~1.5) to 1.9.
- Max line-length capped at 60ch via `max-w-prose` so eyes don't have to track far horizontally.
- Letter-spacing `0.01em` increase across body copy.
- Paragraph spacing roughly equal to one and a half line heights so paragraphs don't blur into each other.
- Italics replaced with bold + colour for emphasis where used (rare in our current copy, but worth a global rule).

**Implementation**

Add a single column to `users`:

```
display_mode  varchar(32) default 'standard'  -- 'standard' | 'high_clarity'
```

Header strip (already exists in [layouts/dashboard.blade.php:202-210](resources/views/layouts/dashboard.blade.php#L202-L210)) gains a small toggle: a single button cycling between modes, with a clear label ("Standard" / "High clarity"). Pref persists immediately on toggle (POST to `/preferences/display`, returns 204, page reloads or swaps the body class via Alpine).

Body class drives everything: `<body class="mode-standard">` or `<body class="mode-high-clarity">`.

CSS lives in a single Tailwind layer that gets compiled. Sketches:

```css
body.mode-high-clarity {
    --line-height-body: 1.9;
    --gap-card: 1.75rem;
    --border-panel: 2px;
    --letter-spacing: 0.01em;
}
body.mode-high-clarity main { max-width: 56rem; }  /* single column */
body.mode-high-clarity table.clarity-tabular { display: none; }
body.mode-high-clarity .clarity-cards { display: block; }
body.mode-standard table.clarity-tabular { display: table; }
body.mode-standard .clarity-cards { display: none; }
body.mode-high-clarity * { animation: none !important; transition: none !important; }
body.mode-high-clarity em, body.mode-high-clarity i { font-style: normal; font-weight: 600; }
```

The `<x-clarity-table>` component renders both shapes server-side; CSS picks which is visible. Costs us a bit of HTML weight but avoids needing JS to switch.

**Charts**

Each chart widget grows a sibling `<x-chart-as-list>` partial that renders the chart data as a labeled card stack. In `mode-high-clarity` the chart hides and the list shows; in `mode-standard` the chart shows. The list is also available to standard-mode users via a small "Show as list" toggle on each widget header (cheap to add, useful for screen reader users too).

**Per-widget audit**

Walk every widget once and decide:

- Does it currently use a `<table>`? If yes, port to `<x-clarity-table>`.
- Does it use a chart? If yes, add the list fallback.
- Does it use icons-only labels (no text)? If yes, add visible text labels.
- Does it use muted (`text-muted`) for primary content? If yes, demote to bg or move to a heavier weight.

**Phasing**

- Phase A: ship the toggle, persist the preference, wire the body class. No widgets converted yet but the mode exists. (Half a day.)
- Phase B: convert tables (highest pain point per her feedback). `action-queue` first since it's the densest. Then `recently-inactive`, then the rest. (One widget per sitting, each ~30 min.)
- Phase C: chart-as-list fallbacks. (Couple of hours.)
- Phase D: typography pass on the rest of the UI (forms, modals, headers).

She can use the dashboard usefully after Phase A+B alone. C and D are polish.

**Out of scope** (worth naming so we don't drift):

- Screen reader / NVDA testing as a separate workstream. Necessary for full WCAG compliance, but the GM hasn't asked for it and our current copy is mostly there already.
- A separate "GM theme" or branded variant. This is a display preset, not a personalisation feature.
- Per-widget accessibility opt-outs. The mode is global; widgets don't get to ignore it.

**Sanity check**

Before committing the design, share Phase A+B with her on a real raid night and watch her use it. Watch where her eyes go, where she squints, where she moves her head. The spec above is a starting point; her feedback over a real session is the ground truth.

### 6.5 Brand asset integration

**Inventory** (as delivered into `docs/`):

- **`docs/LOGO/`**: phoenix mark in many crops/sizes (`LOGO ver_1.png` through `v_5`, `LOGO FINAL.ai`, `Banner.jpg`, `Logo Banner PNG.png`, `Logo banner 1-4.jpg`, `Welcome banner 1.jpg`, `Sticker_emoji.png`, `test gif 1.gif`, `Untitled-1/2.ai`).
- **`docs/Icons/`**: 13 class PNGs + a `class type/` subfolder with role art (DPS/Healer/Tank). Note: `Munk.png` is a typo for Monk.
- **`docs/Icons new/`**: the more complete set. 13 classes (lowercase, `monk` correctly named), 13 professions (`alchemy`, `blacksmithing`, `cooking`, `enchanting`, `engineering`, `fishing`, `herbalism`, `inscription`, `jewelcrafting`, `leatherworking`, `mining`, `skinning`, `tailoring`), 4 roles (`tank`, `healer`, `melee`, `ranged`), 4 guild-role badges (`GM`, `OFFICER`, `Moderator`, `Raid lead`), 4 activities (`Delves`, `mythicplus`, `pvp`, `raid`).

**Asset palette** (read off the logo + role badges):

- Deep red phoenix: ~`#A8262E`
- Off-white plumage: matches existing `ink` (`#e6e6f0`)
- Black-burgundy outline: ~`#1a1010`
- Banner background: dark grey ~`#2a2a2e`, close to existing `bg`/`panel`

The current Tailwind accent is Discord blue (`#5865F2`). Brand is red. Worth a discussion: rebrand the accent to phoenix red, or keep blue for "officer tooling neutrality" and use red only for hero / decorative surfaces. My lean is **rebrand**: the dashboard is internal to Regenesis, branding to the guild reads better than to Discord.

**Step 0: file move + filename normalisation (DONE)**

Copied from `docs/Icons new/` and `docs/LOGO/` into `public/img/`. Originals remain in `docs/` as source-of-truth. Final paths:

```
public/img/brand/
    phoenix-mark.png            (square mark, round red badge)
    phoenix-wordmark.png        (mark + "Regenesis" wordmark, transparent bg)
    phoenix-wordmark-large.jpg  (same wordmark on dark grey, full bleed)
    phoenix-emoji.png           (transparent phoenix, no badge ring)
    welcome-banner.jpg          (decorated landing banner)

public/img/icons/class/
    deathknight.png  demonhunter.png  druid.png      evoker.png
    hunter.png       mage.png         monk.png       paladin.png
    priest.png       rogue.png        shaman.png     warlock.png
    warrior.png

public/img/icons/role/
    tank.png  healer.png  melee.png  ranged.png

public/img/icons/profession/
    alchemy.png        blacksmithing.png  cooking.png       enchanting.png
    engineering.png    fishing.png        herbalism.png     inscription.png
    jewelcrafting.png  leatherworking.png mining.png        skinning.png
    tailoring.png

public/img/icons/guild-role/
    gm.png  officer.png  moderator.png  raid-lead.png

public/img/icons/activity/
    delves.png  mythic-plus.png  pvp.png  raid.png

public/favicon.png              (copy of phoenix-mark.png)
```

Filename normalisation rules applied: all lowercase, hyphens not spaces, no caps. Class filenames map directly to `Str::lower($member->class)`. Activity rename: `mythicplus.png` -> `mythic-plus.png` for consistency with the rest.

**Step 0a: WebP siblings + Blade component (DONE)**

- WebP generator at [tools/build-webp.php](tools/build-webp.php). Walks `public/img/`, downscales any source PNG/JPG whose longest side exceeds 1200px (the brand masters were print-res, e.g. phoenix-mark.png was 16647x8746), then emits a quality-85 WebP sibling. Re-runnable.
- Run from repo root: `"C:/Users/r/.config/herd/bin/php84/php.exe" -d memory_limit=2048M tools/build-webp.php`. Initial run produced 42 WebP siblings, downscaled 4 brand assets, total deployed weight now 3MB (was over 8MB).
- Blade component at [resources/views/components/icon.blade.php](resources/views/components/icon.blade.php). Usage: `<x-icon kind="class" name="paladin" :size="20" />`. Emits a `<picture>` with WebP source + PNG fallback, lazy + async by default. Auto-alt text per kind: class -> "Paladin class icon", guild-role -> "Guild Master" / "Officer" / etc, brand -> "Regenesis".
- `kind` accepts: `class`, `role`, `profession`, `guild-role`, `activity`, `brand`. `name` is the file basename without extension.
- `.gitignore` updated to allow `/docs/` (line previously excluded the whole folder to keep raid-helper / wowaudit doc scrapes with API keys out of git; user has rotated all keys, so the folder is now committed).

**Step 1: asset-by-asset placement plan**

#### Brand assets (5 files)

| File | Used at | Treatment |
|---|---|---|
| `phoenix-mark.png` | Sidebar header at [layouts/dashboard.blade.php:174-177](resources/views/layouts/dashboard.blade.php#L174-L177) | 28px square mark to the left of the "Regenesis" wordmark text. Mark + text together as the "home" link. |
| `phoenix-mark.png` | Favicon (already at `public/favicon.png`) | Round red badge reads well at 16-32px because the solid red circle gives it definition against any tab background. **Recommended favicon source.** Add `<link rel="icon">` tags pointing at `/favicon.png` plus 180x180 apple-touch-icon variant. |
| `phoenix-mark.png` | Discord webhook avatars | When we post to Discord (event reminders, sync digests if added), set the webhook avatar to this. |
| `phoenix-mark.png` | Email "from" avatar / Gravatar fallback | If we ever add transactional email. Defer. |
| `phoenix-emoji.png` | Tiny transparent contexts | Loading spinners, page transitions, empty-state illustrations. Cleaner than the badge when no background ring is wanted. |
| `phoenix-emoji.png` | Discord custom emoji | If we add a Discord bot integration that wants to react with the guild mark. |
| `phoenix-wordmark.png` | Landing page hero in [resources/views/landing.blade.php](resources/views/landing.blade.php) | Top of page, transparent so it sits on whatever bg we pick. |
| `phoenix-wordmark.png` | Login / `auth/discord` pages | Above the "Sign in with Discord" button. Smaller crop than landing. |
| `phoenix-wordmark.png` | Unauthorised / failed-auth pages | Same treatment as login, sets the brand tone before the user reads the error. |
| `phoenix-wordmark-large.jpg` | Open Graph / social card image | The dark-grey-background version is what previews well in Discord/Slack/Twitter unfurls. Add `<meta property="og:image">` pointing at it. |
| `phoenix-wordmark-large.jpg` | Print / PDF officer report header | If we add weekly digest PDF. Defer. |
| `welcome-banner.jpg` | First-login welcome screen | One-time greeting after Discord OAuth completes for a brand-new officer. Skip-able. |
| `welcome-banner.jpg` | About / "what is this" page if we add one | Otherwise unused; nice-to-have, not load-bearing. |

**On the favicon question specifically:** `phoenix-mark.png` is the right source. It's already a round badge with strong red fill, so even at 16x16 it reads as "Regenesis" rather than as a smudge. The transparent `phoenix-emoji.png` would lose definition against a white browser tab and look weak. To do it properly later: generate `favicon.ico` (16+32+48 multi-res), `favicon-32x32.png`, `apple-touch-icon.png` (180x180), `android-chrome-192x192.png`, `android-chrome-512x512.png`, and a `site.webmanifest`. For now the single `public/favicon.png` works in modern browsers via the default `/favicon` request path.

#### Class icons (13 files)

Used wherever a character name renders. Pair with class-coloured text, never replace it (high-clarity mode rule).

| Surface | Icon size | Notes |
|---|---|---|
| Roster page (§6.1) class column | 20px | Beside class-coloured name. |
| Action-queue widget | 16px | Compact rows, smaller icon. |
| Recently-inactive widget | 20px | (Or its successor inside Roster.) |
| Alt-groups widget | 18px | Beside main + each alt name. |
| Anniversaries widget | 16px | Beside name. |
| Log timeline | 14px | Inline in event rows. |
| Bans widget | 18px | Beside banned member name. |
| Kick-macro modal (§6.2) | 24px | Larger so the officer can visually verify they're hitting the right characters. |
| Vault-progress, M+ this week, attendance | 16-18px | Wherever a name appears. |
| Future character detail page | 48px | Hero element. |

Helper: `<x-icon kind="class" :name="strtolower($member->class)" :size="20" />`.

#### Role icons (4 files: tank / healer / melee / ranged)

Role is implied by spec. Where we have the spec from `member_snapshots`, render the role icon. If only class is known, omit.

| Surface | Notes |
|---|---|
| Roster page role column | Small chip between Rank and ilvl. |
| Team-progression widget | Roster composition row (e.g. "3 tanks / 5 healers / 12 dps"). |
| Vault-progress | Group rows by role. |
| Attendance | Show role per row to spot composition gaps. |
| Kick-macro modal | If kicking a tank or healer, surface a small "removing core role" warning. Optional. |
| Composition planner (future) | Headline use; defer until the planner is on the table. |

Note: the icons split DPS into `melee` and `ranged`, which is more useful for raid composition than a single DPS bucket. Where we just need "DPS", pick one (probably `melee` as the visual default) or use a generic placeholder. Don't ship a fake `dps.png`.

#### Profession icons (13 files)

Mostly cold storage until we add a character detail page. The members table has `profession_1_id` / `profession_2_id` but the dashboard doesn't surface them yet.

| Surface | Notes |
|---|---|
| Character detail page (future) | Beside the two profession skill numbers. |
| Crafting requests / officer notes (future) | If we ever build "find me an enchanter" lookup. |
| Roster export CSV | Skip; emoji/icon doesn't transfer to CSV cleanly. |

For T1 work, just keep them on disk and don't reference them yet. Wiring them in is a single afternoon's work whenever the character detail page lands.

#### Guild-role badges (4 files: gm / officer / moderator / raid-lead)

These are Discord-role-driven, sourced from `users.tier` (set on Discord OAuth login per the role gate in `.env`). The badge corresponds to which Discord role the user holds at sign-in.

| Surface | Notes |
|---|---|
| Sidebar footer at [layouts/dashboard.blade.php:202-210](resources/views/layouts/dashboard.blade.php#L202-L210) | Replace the small uppercase `{{ tier }}` text with `<x-icon kind="guild-role" :name="$user->tier" />` + label. |
| Events page, raid signup list | Mark raid leads visually so officers can spot "who's leading tonight" at a glance. |
| Event creator form | Show the badge of the event organiser. |
| Action queue | Decisions touching rank changes can show "this requires GM approval" badge. |
| Audit trail / `MemberAction` log | Show which officer logged each action. |

**Mapping**: `tier` values (set in Discord OAuth flow) -> badge filenames:
- `gm` -> `gm.png`
- `big6` -> ??? — there's no Big6 badge in the set yet. Either commission one, alias to `officer.png` for now, or fall back to a generic shield. Decide before wiring this up. Flag for the planning session.
- `officer` -> `officer.png`
- `moderator` -> `moderator.png`
- `raid_lead` -> `raid-lead.png` (note: this is a separate concept from `tier`; raid lead is per-event, not per-user. The badge is for event roles, not the sidebar footer.)

#### Activity icons (4 files: delves / mythic-plus / pvp / raid)

| Surface | Notes |
|---|---|
| Team-progression widget | Header per progression block. |
| Keynight (M+) page header | `mythic-plus.png` as page mark. |
| Heroic / Mythic team pages | `raid.png` in page header. |
| Event creator + event list | Marker per event row indicating activity type. |
| Future PvP and Delves pages | Page headers when those exist. |
| Vault-progress widget | Three sections for raid / M+ / PvP vault choices. |

Mapping is straightforward: event type or page subject -> matching file.

**Step 2: per-feature integration in the existing planned work**

These are concrete spots in features already speced above where the icons slot in. Doing them as part of the original feature work avoids retrofitting later.

- **Roster page (§6.1)**: Class column gets `<x-icon kind="class" :name="$m->class" size="20" />` + class-coloured name. Role chip column (`<x-icon kind="role" :name="$role" />`) sits between Rank and ilvl. Officer-flag pills swap text for the matching guild-role badge where it's a rank-level recommendation.
- **Kick macro modal (§6.2)**: Each character in the deselect list shows their class icon. Visual confirmation that the macro hits the right people, especially when alt names are unfamiliar.
- **High-clarity mode (§6.4)**: Icons stay, but get a `1.5x` size bump and a hard `aria-label` so screen readers and zoomed-in viewers don't lose the meaning. Class icons paired with both the icon AND the class name in text (no icon-only labels in this mode).
- **External links (§6.6 below)**: External-service buttons use small monochrome icons (Armory, RIO, WCL favicons, sourced separately). Distinct enough from the brand icons to not look like guild content.

**Step 3: discord_username / current user pill**

The sidebar footer in [layouts/dashboard.blade.php:202-210](resources/views/layouts/dashboard.blade.php#L202-L210) currently shows `discord_username` + a tiny uppercase `{{ tier }}` label. With the new badges:

- `tier` becomes `<x-icon kind="guild-role" :name="$user->tier" size="20" />` + label.
- If the user has a Discord avatar URL on record, render it as a small circle next to the username.

**Out of scope**

- Animated logo / parallax / scroll effects on landing. The high-clarity mode forbids motion, and the GM is in scope as a primary user.
- Custom CSS-drawn icons or SVG conversion. Stick with the PNGs we have. Revisit if size becomes an issue.
- Theming: no light mode. The brand is built for dark; light mode would need separate art.

**Sequencing**

Do the file move + filename normalisation + `<x-icon>` component as a single first commit. After that lands, every feature that's already speced (Roster, kick macros, high-clarity, external links) can grow icon usage incrementally without further infrastructure work.

### 6.6 Optional phoenix-red colour theme

**Why this is a separate axis from high-clarity mode**

The high-clarity mode (§6.4) controls *display structure* (single column, stacked cards, big spacing, no motion). The colour theme controls *brand chrome* (accent colour, link colour, button colour, focus rings). They're orthogonal: a user could want phoenix-red + high-clarity, or phoenix-red + standard, or Discord-blue + high-clarity. So they need to be independent preferences, not a four-way enum.

Concretely:

```
users.display_mode  varchar(32)  default 'standard'   -- standard | high-clarity
users.theme         varchar(32)  default 'discord'    -- discord  | phoenix
```

Two columns, two body classes, two CSS sections. No combinatorial explosion.

**The two themes**

`theme=discord` (current default, no behaviour change for existing users):
- Accent: `#5865F2` (Discord blurple)
- Existing palette in [layouts/dashboard.blade.php:103-117](resources/views/layouts/dashboard.blade.php#L103-L117) stays intact.

`theme=phoenix` (new, opt-in):
- Accent: `#A8262E` (phoenix red, sampled from logo)
- Accent-hover: `#C73344` (a touch lighter)
- Active link / focus ring: same red, pulled from logo's outer feathers
- Optional bg shift: very slight warm-up of the panel colour to harmonise with red (`#161018` instead of `#15151f`). Keep optional - might fight contrast targets.

The `bg`, `panel`, `line`, `ink`, `muted` palette stays the same in both themes. The phoenix red just replaces the Discord blurple accent. This is the smallest possible swap that reads as "branded" without re-skinning the whole UI.

**Implementation**

Tailwind config is currently inline in the layout. Approach:

```html
<body class="theme-{{ $user->theme ?? 'discord' }} mode-{{ $user->display_mode ?? 'standard' }}">
```

CSS variables override the Tailwind colours per theme:

```css
body.theme-discord { --c-accent: #5865F2; --c-accent-hover: #4752C4; }
body.theme-phoenix { --c-accent: #A8262E; --c-accent-hover: #C73344; }
```

Replace direct Tailwind `accent` references in the layout (`bg-accent`, `text-accent`, `border-accent`) with `bg-[var(--c-accent)]` etc. Or extend the Tailwind config to define `accent` as `var(--c-accent)`. The latter is cleaner and keeps the existing class names working.

```js
tailwind.config = {
    theme: {
        extend: {
            colors: {
                bg: '#0b0b14',
                panel: '#15151f',
                line: '#252533',
                ink: '#e6e6f0',
                muted: '#7a7a8c',
                accent: 'var(--c-accent)',         // theme-driven
                'accent-hover': 'var(--c-accent-hover)',
            },
        },
    },
};
```

Then `bg-accent` resolves to whichever red or blue the active theme set in CSS variables. Existing classes need no edits.

**Picker UI**

Lives wherever the high-clarity toggle ends up (likely sidebar footer or a dedicated `/preferences` page once we have more than two settings). Two radio buttons or icon-pill toggles: "Discord" (blue dot) / "Phoenix" (red dot). Persists immediately on click.

**Defaults and migration**

- New users default to `theme=discord` (no visual surprise compared to current state).
- Existing users keep `theme=discord` automatically (the column default).
- Internal preference: when the planning session decides phoenix is the brand-correct default, flip the column default to `phoenix` in a follow-up migration. That's a minute-level decision.

**Flag for the planning session**

This shape doesn't preclude adding more themes later (a "high-contrast" pure black/white theme would be a third option). Keep the column type as `varchar(32)` rather than an enum so we can extend without a schema change.

### 6.7 Character external-links component

**Component**: `<x-character-links :member="$m" />` at `resources/views/components/character-links.blade.php`.

**Output**: small horizontal strip of icon links, one per external service. Each link opens in a new tab.

**Targets** (with URL templates):

| Service | URL template | Notes |
|---|---|---|
| WoW Armory | `https://worldofwarcraft.com/en-gb/character/eu/{realm-slug}/{name}` | needs realm slug lowercased and de-spaced |
| RaiderIO | `https://raider.io/characters/eu/{realm-slug}/{name}` | |
| Warcraft Logs | `https://www.warcraftlogs.com/character/eu/{realm-slug}/{name}` | |
| WoW Analyzer | `https://wowanalyzer.com/character/EU/{realm-slug}/{name}` | parses last 10 logs server-side |
| Murlok.io | `https://murlok.io/character/eu/{realm-slug}/{name}` | gear/M+ analytics |
| Check-PvP | `https://check-pvp.fr/eu/{realm-slug}/{name}` | optional, only if guild does PvP |

**Realm-slug helper**: small private method on the component or a shared helper that takes `members.realm` (added in `2026_04_26_140000_add_realm_to_members.php`) and applies `Str::slug($realm, '-')`. Lower-case, hyphens. Falls back to the configured guild realm (`Silvermoon`) if the column is null.

**Where it goes**:

- Roster row (right-aligned in the actions column)
- `member-actions` modal (already exists per `MemberAction`)
- Future character detail page

**Skip per CLAUDE.md**: don't make it configurable per guild. Single guild dashboard. Hard-code the list.

## 7. Reference: Copilot's draft

Kept here for context; treat as a starting point, not the spec.

> *(Copilot's PRD content lives in chat history. Re-paste if you want it embedded; not duplicated here to avoid drift between chat and repo.)*
