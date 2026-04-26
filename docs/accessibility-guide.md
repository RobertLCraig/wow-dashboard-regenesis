# Accessibility guide: high-clarity mode

A drop-in pattern for adding a per-user "high-clarity" display mode to a
small web app. Written from what worked (and what didn't) building this
mode for an officer with horizontal + vertical diplopia and rotational
tilt. Most of it is generic, with Laravel/Blade/Tailwind specifics
called out as such.

## Who this is for

Anyone building a small internal tool where one of your users:

- has diplopia (sees double horizontally, vertically, or both),
- has age-related vision changes, migraine sensitivity, or low vision,
- gets eye strain on dense data dashboards,
- runs the app at 200% browser zoom and it falls apart.

You don't need a designated "accessible alternative" page. You need the
existing UI to have a switch that loosens up its typography, calms
animation, and gives tables vertical breathing room. That's it.

## What we tried first and dropped

These felt right on paper and were wrong in practice. Skip them.

### 1. Forced single-column layout

The plan was: in high-clarity mode, override every responsive grid to
single column so the user never has to scan horizontally between two
adjacent widgets.

What actually happened: on a 1440p or 4K monitor, this turned the
dashboard into a narrow ribbon down the left side with a vast empty
right margin. Stat cards that were happily 4-up at 30px height each
became four full-width rows of 80px each. Reading the dashboard
required a full screen of vertical scroll where standard mode showed
everything in one screen.

The misread: "horizontal scan is bad" is about reading **text**, not
about glancing between **panels**. Glancing across to a small KPI card
beside the current one is fine. Reading a 200-character line is not.

What to do instead: leave the responsive grid alone. Constrain text
width per widget if you have widgets with long prose (and almost
none do).

### 2. Narrow `max-width` on the page container

Same thinking, same mistake. We capped `main` at 56rem (~896px) so
"the user wouldn't have to look across the whole monitor". On a wide
monitor this just wastes the right half of the screen.

The misread: the user's eye doesn't have to start at the left edge of
the monitor and travel to the right edge. It travels within whatever
visual block they're currently focused on. Per-widget width matters;
container width doesn't.

### 3. Convert every table into stacked cards

The plan was: in high-clarity mode, every `<table>` collapses into a
list of cards, one per row, with each cell rendered as `Label: Value`.
We added a `clarity-tabular` CSS class + `data-label="..."` convention
across every widget table. The CSS used `display: block` overrides on
table elements + `td[data-label]::before { content: attr(data-label) }`.

What actually happened: tables that lived in a 1/3-width grid cell
became cards that were also 1/3-width. Each card had three or four
labelled lines stacked, with bordered backgrounds. Visually busier
than the original table, not calmer.

The misread: `<table>` markup isn't the problem for someone with
diplopia. Rows that visually merge into each other are. The fix is
**vertical padding on cells**, not a structural transformation.

You can keep the `clarity-tabular` + `data-label` markup in case you
want it later for a different mode (high-contrast, single-column,
print). It's harmless when the CSS isn't there.

### 4. Toggle label that names the destination

Our first toggle button label flipped between "High-clarity view" and
"Standard view" depending on current state, and showed an "ON / OFF"
indicator alongside. The intent was "this button takes you to the
named view". The user read it as "[named view] is currently [ON/OFF]"
and got the wrong end of the stick every single time.

Concretely: when high-clarity was active, the button said
`Standard view  ON` because clicking would take you to standard. The
user reads "Standard view is on" and is now confused about whether
they're already in standard.

The fix: the label always names **one mode**, and the indicator
always reflects whether **that mode** is on. Click flips it.

```
High-clarity view  ON   <- mode is currently active, click to turn off
High-clarity view  OFF  <- mode is currently inactive, click to turn on
```

## What actually worked

Light touches. Layout stays the same. Typography and spacing nudge.

### CSS you can drop in

```css
body.mode-high-clarity { line-height: 1.7; letter-spacing: 0.01em; }

/* Motion off. Honour prefers-reduced-motion always; add an explicit
   override for users who don't have the OS setting. */
body.mode-high-clarity *,
body.mode-high-clarity *::before,
body.mode-high-clarity *::after {
    animation-duration: 0.001ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.001ms !important;
}

/* Italics turn into bold-coloured. Italic faces become double-vision
   smudges under torsion, and most italics in a UI are decorative. */
body.mode-high-clarity em,
body.mode-high-clarity i { font-style: normal; font-weight: 600; }

/* One-tier font-size bump across the whole text scale. If you use
   Tailwind, override the utilities directly so existing class names
   keep working: */
body.mode-high-clarity .text-xs   { font-size: 0.8125rem;  } /* 13px */
body.mode-high-clarity .text-sm   { font-size: 0.9375rem;  } /* 15px */
body.mode-high-clarity .text-base { font-size: 1.0625rem;  } /* 17px */
body.mode-high-clarity .text-lg   { font-size: 1.1875rem;  } /* 19px */
body.mode-high-clarity .text-xl   { font-size: 1.375rem;   } /* 22px */

/* Vertical padding on table cells. The single biggest accessibility
   win. Adjacent rows stop visually merging under vertical diplopia. */
body.mode-high-clarity table tbody td,
body.mode-high-clarity table thead th { padding-top: 0.65rem; padding-bottom: 0.65rem; }

/* Bit more padding on widget header strips. */
body.mode-high-clarity section > header { padding-top: 1rem; padding-bottom: 1rem; }

/* Lift "muted" text contrast a touch. Secondary metadata stays
   visually demoted but stops being borderline-illegible. */
body.mode-high-clarity .text-muted { color: #9494a5; }
```

That's the whole CSS layer. Twenty lines of override and the dashboard
becomes meaningfully easier on tired eyes without any structural
change.

### What each line is doing for which symptom

| Symptom                | Override                                   |
| ---------------------- | ------------------------------------------ |
| Vertical diplopia      | Cell padding, line-height, header padding  |
| Horizontal diplopia    | Letter-spacing, slightly larger text       |
| Rotational tilt        | Italics off, no condensed faces, no animation |
| Eye strain / migraine  | No motion, calmer contrast curve           |
| 200% browser zoom      | Per-tier rem sizing, no fixed-px body text |

### What you do NOT need to do

- Hide the table headers.
- Stack tables into cards.
- Force single-column flow.
- Cap container width.
- Build a parallel "accessible UI" page.
- Detect `prefers-reduced-motion` and force the mode on. (Honour it
  for motion specifically, but the mode itself stays user-choice.)

## Implementation pattern

The mode is a single string column on the user, a body class on
every page, and a CSS layer driven by that class. No JS at all on
the toggle path.

### 1. User column

```php
// Migration
Schema::table('users', function (Blueprint $table) {
    $table->string('display_mode', 32)->default('standard')->after('tier');
});

// Model
public const DISPLAY_STANDARD     = 'standard';
public const DISPLAY_HIGH_CLARITY = 'high_clarity';

protected $fillable = [..., 'display_mode'];
```

`varchar(32)` not enum: lets you add `high_contrast`, `compact`, etc.
later without a schema change.

### 2. Body class

```php
@php
    $displayMode = auth()->user()?->display_mode ?? \App\Models\User::DISPLAY_STANDARD;
    $bodyMode = $displayMode === \App\Models\User::DISPLAY_HIGH_CLARITY
        ? 'mode-high-clarity'
        : 'mode-standard';
@endphp
<body class="... {{ $bodyMode }}" ...>
```

Every override in the CSS layer is scoped to `body.mode-high-clarity`.
Standard mode has no overrides at all, so the no-op cost is zero.

### 3. Persistence endpoint

```php
// Routes
Route::post('/preferences/display', [PreferencesController::class, 'display'])
    ->name('preferences.display');

// Controller
public function display(Request $request): RedirectResponse
{
    $data = $request->validate([
        'display_mode' => ['required', Rule::in([
            User::DISPLAY_STANDARD,
            User::DISPLAY_HIGH_CLARITY,
        ])],
    ]);

    $request->user()->forceFill(['display_mode' => $data['display_mode']])->save();

    return redirect()->back();
}
```

Plain HTML form. No fetch, no Ajax, no JS framework. Submitting
re-renders the page in the new mode immediately.

### 4. Toggle UI

In the sidebar footer or header strip:

```blade
<form method="POST" action="{{ route('preferences.display') }}">
    @csrf
    @php
        $hc = $displayMode === \App\Models\User::DISPLAY_HIGH_CLARITY;
    @endphp
    <input type="hidden" name="display_mode"
           value="{{ $hc ? \App\Models\User::DISPLAY_STANDARD : \App\Models\User::DISPLAY_HIGH_CLARITY }}">
    <button type="submit" class="...">
        <span>High-clarity view</span>
        <span class="{{ $hc ? 'text-emerald-300' : 'text-muted/60' }}">
            {{ $hc ? 'ON' : 'OFF' }}
        </span>
    </button>
</form>
```

Label always names the **same** mode (high-clarity). Indicator shows
its **current** state. Hidden input sends the **opposite** value so
submitting flips. Three rules; never deviate from any of them.

### 5. Tests worth having

```php
it('defaults new users to standard display mode', function () { ... });
it('persists a switch to high-clarity mode', function () { ... });
it('persists a switch back to standard mode', function () { ... });
it('rejects an unknown display_mode value', function () { ... });
it('requires authentication', function () { ... });
it('renders body with mode-standard for a default user', function () { ... });
it('renders body with mode-high-clarity once the user opts in', function () { ... });
```

Cheap to write, catch regressions when someone refactors the layout
or adds a new mode value.

## Toggle UX rules (don't violate these)

1. **Universal access.** The toggle is visible to every signed-in
   user. Not gated to one role, not hidden behind a settings page
   they have to find. Sidebar footer or header dropdown.
2. **Persists immediately.** One click, page reloads in the new mode.
   No "Save preferences" button.
3. **Label names a mode, not a destination.** See section above. Get
   this wrong and every user thinks the toggle is broken.
4. **Per-user, not per-device.** Stored on the user, follows them
   across browsers and machines. Don't use localStorage for this.
5. **Defaults to standard.** Even if you personally think
   high-clarity is better, the default has to be the current
   experience so nothing changes for existing users on rollout.
6. **One-click reversible.** No confirmation dialog. No "are you
   sure". Click on, click off.

## Carry-over checklist for a new project

When you're scaffolding the same mode somewhere else, in order:

- [ ] Add `users.display_mode varchar(32) default 'standard'`.
- [ ] Add the constants on the User model + add to fillable.
- [ ] Build the `PreferencesController@display` endpoint behind auth.
- [ ] Wire the body class in the layout.
- [ ] Drop the CSS layer from this guide into the layout's `<style>`
      block (or a dedicated CSS file if you have a build step).
- [ ] Add the toggle form somewhere always-visible (sidebar footer
      is the obvious spot).
- [ ] Write the seven tests above.
- [ ] Try it on actual data with someone who'd benefit.

That's the whole job. Half a day of work for a real win.

## Things to leave for "phase 2" if a real user asks

These have a place but are not load-bearing for a v1:

- **Density preference** (compact / cosy / comfortable). Different
  axis from clarity. Padding-heavy, not contrast-heavy.
- **Theme preference** (e.g. brand colours vs default). Orthogonal
  again. Use a separate column, separate body class.
- **Per-widget show/hide / drag-to-reorder.** Real customisation.
  Adds persistence + JS state. Wait for a complaint.
- **Chart-as-list fallback.** If a user can't read your charts, give
  each chart a "Show as list" toggle that renders the same data as a
  labelled card stack. Genuinely useful, low priority until asked.
- **Screen reader audit.** Necessary for full WCAG compliance.
  Different workstream. Don't conflate it with display mode.

## Notes on browser zoom and OS settings

Every accessibility helper the user has from the OS or browser is
**already running** when they hit your page. Your CSS has to survive
their zoom level (use rem, not px, for body text), their OS
high-contrast theme, and their magnifier. Don't fight any of these.

In particular:

- Don't use `vw` units for type. Breaks under zoom.
- Don't use fixed `px` for body copy. Breaks under zoom.
- Test at 200% zoom in Chrome, Firefox, and Edge before shipping.
  Anything that disappears or overflows horizontally is a bug.
- Honour `prefers-reduced-motion: reduce` even when the user is in
  standard mode.

## What this guide is not

- A WCAG compliance checklist. WCAG is broader (keyboard nav, ARIA,
  colour contrast tokens, focus states). This guide covers one
  specific axis (display mode for visual fatigue / diplopia). The two
  overlap but aren't the same.
- A design system. The CSS layer here is a thin override on top of
  whatever you already have.
- A justification for skipping an accessibility audit. If your tool
  is internal-only and you have one user with a known need, this
  pattern is enough. If you ship to the public, get a real audit.
