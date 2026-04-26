# Accessibility guide: clarity dial

A drop-in pattern for adding a per-user "clarity dial" to a small web
app: a 3-step display preference (Standard / Clear / High) that
layers typographic and structural overrides on top of your existing
UI. Written from what worked (and what didn't) building this for an
officer with horizontal + vertical diplopia and rotational tilt.

Most of it is generic, with Laravel/Blade/Tailwind specifics called
out as such.

## Who this is for

Anyone building a small internal tool where one of your users:

- has diplopia (sees double horizontally, vertically, or both),
- has age-related vision changes, migraine sensitivity, or low vision,
- gets eye strain on dense data dashboards,
- runs the app at 200% browser zoom and it falls apart.

You don't need a parallel "accessible UI". You need the existing UI
to have a dial that loosens up its typography, calms animation, and
optionally restructures dense tables. That's it.

## Why three steps and not a binary toggle

The first version was a binary high-clarity toggle. Two problems:

1. **The user can't necessarily tell which mode they're in.** If the
   "on" state is too subtle, they don't perceive the difference and
   the toggle reads as broken. If the "on" state is too aggressive
   (forced single column, narrow page), it feels overcorrected for
   anyone whose needs aren't extreme.
2. **Different users sit on different points of the spectrum.** Mild
   eye strain wants a small typographic nudge. Diplopia wants real
   structural changes. One toggle can't serve both.

A 3-step dial gives the user a perceivable difference at every
click, and gives you a place to put both the light touch and the
real overrides without forcing the wrong one on anyone.

The three steps:

| Step           | What it adds                                                            | Who it's for                                          |
| -------------- | ----------------------------------------------------------------------- | ----------------------------------------------------- |
| **Standard**   | Nothing. Baseline app.                                                  | Default. Most users.                                  |
| **Clear**      | Typography nudge: bigger text, more cell padding, no italics, no motion, lifted muted-text contrast. Layout unchanged. | Mild eye strain, age-related vision, anyone working long hours. |
| **High**       | Everything in Clear, plus structural overrides: responsive grids collapse to single column, tables collapse into stacked cards. | Diplopia, low vision, anyone who can't track horizontally across dense rows. |

Each step layers additively on the previous. Stepping up never
removes a previous gain.

## What we tried first and dropped

These felt right on paper and were wrong in practice. Skip them.

### 1. Narrow `max-width` on the page container at high-clarity

We capped `main` at 56rem (~896px) so "the user wouldn't have to
look across the whole monitor". On a wide monitor this just wastes
the right half of the screen.

The misread: the user's eye doesn't have to start at the left edge
of the monitor and travel to the right edge. It travels within
whatever visual block they're currently focused on. Per-widget
content width matters; container width doesn't.

What to do instead: leave the page container at its full width.
Constrain text inside individual widgets if you have prose-heavy
ones (almost no dashboard does).

### 2. Toggle label that names the destination

Our first toggle said "High-clarity view" / "Standard view" depending
on current state, with an "ON / OFF" indicator. The intent was
"this button takes you to the named view". The user read it as
"[named view] is currently [ON/OFF]" and got the wrong end of the
stick every single time.

The fix when you do have a binary toggle: the label always names one
mode, and the indicator reflects whether **that mode** is on.

The fix when you have a 3-step dial: just show three labelled
buttons in a segmented control with the active one highlighted.
There's no "destination" to name; each button IS the destination.

### 3. Binary toggle with too-subtle differences

Once we backed off the structural overrides (because they were too
aggressive on wide monitors), the binary toggle's "on" state became
just a typography nudge. The user couldn't reliably tell which mode
they were in by looking, which made the toggle feel broken.

The fix: a 3-step dial. The bottom step (Standard) is the baseline;
the top step (High) is genuinely different from baseline, with the
structural overrides back; the middle step (Clear) is the gentle
nudge for users who don't need the full treatment.

## What actually works at each step

### Step 1: Standard

No CSS overrides. Your existing UI as-is. This is the default for new
users so rollout doesn't change anyone's experience without consent.

### Step 2: Clear

A pure typography + spacing layer. Layout is unchanged.

```css
body.mode-clear, body.mode-high-clarity {
    line-height: 1.7;
    letter-spacing: 0.01em;
}

/* Motion off. Honour prefers-reduced-motion always; this also
   covers users who don't have the OS setting. */
body.mode-clear *, body.mode-clear *::before, body.mode-clear *::after,
body.mode-high-clarity *, body.mode-high-clarity *::before, body.mode-high-clarity *::after {
    animation-duration: 0.001ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.001ms !important;
}

/* Italics turn into bold. Italic faces become double-vision smudges
   under torsion, and most italics in a UI are decorative. */
body.mode-clear em, body.mode-clear i,
body.mode-high-clarity em, body.mode-high-clarity i {
    font-style: normal;
    font-weight: 600;
}

/* One-tier font-size bump per Tailwind text-* utility. Existing
   class names keep working; the rendered size is one notch larger. */
body.mode-clear .text-xs,        body.mode-high-clarity .text-xs        { font-size: 0.8125rem; }
body.mode-clear .text-sm,        body.mode-high-clarity .text-sm        { font-size: 0.9375rem; }
body.mode-clear .text-base,      body.mode-high-clarity .text-base      { font-size: 1.0625rem; }
body.mode-clear .text-lg,        body.mode-high-clarity .text-lg        { font-size: 1.1875rem; }
body.mode-clear .text-xl,        body.mode-high-clarity .text-xl        { font-size: 1.375rem;  }

/* Vertical padding on table cells. Single biggest accessibility
   win. Adjacent rows stop visually merging under vertical diplopia. */
body.mode-clear table tbody td, body.mode-clear table thead th,
body.mode-high-clarity table tbody td, body.mode-high-clarity table thead th {
    padding-top: 0.65rem;
    padding-bottom: 0.65rem;
}

/* More room around widget header strips. */
body.mode-clear section > header,
body.mode-high-clarity section > header { padding-top: 1rem; padding-bottom: 1rem; }

/* Lift "muted" text contrast slightly. Secondary metadata stays
   visually demoted but stops being borderline-illegible. */
body.mode-clear .text-muted,
body.mode-high-clarity .text-muted { color: #9494a5; }
```

### Step 3: High

Everything from Clear, plus structural overrides. This is where you
bring back the table-as-cards conversion and the single-column flow.

```css
/* Bigger typographic step on top of the layer-1 bump. */
body.mode-high-clarity { line-height: 1.85; }
body.mode-high-clarity .text-xs   { font-size: 0.875rem;  } /* 14px */
body.mode-high-clarity .text-sm   { font-size: 1rem;      } /* 16px */
body.mode-high-clarity .text-base { font-size: 1.125rem;  } /* 18px */
body.mode-high-clarity .text-lg   { font-size: 1.25rem;   } /* 20px */
body.mode-high-clarity .text-xl   { font-size: 1.5rem;    } /* 24px */

/* Force every responsive grid to single-column. Adjacent widgets
   stack instead of sitting side-by-side, so the user never has to
   glance horizontally between two regions. */
body.mode-high-clarity .grid {
    display: flex !important;
    flex-direction: column;
    gap: 1.75rem;
}

/* Tables that opt-in via class="clarity-tabular" collapse into a
   stacked-card list. Each row becomes a bordered card; each cell
   becomes a labelled line via data-label + ::before pseudo-element. */
body.mode-high-clarity table.clarity-tabular,
body.mode-high-clarity table.clarity-tabular thead,
body.mode-high-clarity table.clarity-tabular tbody,
body.mode-high-clarity table.clarity-tabular tr,
body.mode-high-clarity table.clarity-tabular td,
body.mode-high-clarity table.clarity-tabular th {
    display: block;
    border: none;
    text-align: left !important;
}
body.mode-high-clarity table.clarity-tabular thead { display: none; }
body.mode-high-clarity table.clarity-tabular tbody tr[data-row] {
    border: 2px solid #2a2a35;
    border-radius: 0.5rem;
    padding: 0.85rem 1rem;
    margin: 0 0.75rem 0.85rem;
    background: rgba(0,0,0,0.15);
}
body.mode-high-clarity table.clarity-tabular tbody tr[data-row] td:first-child {
    font-size: 1.1em;
    font-weight: 600;
    margin-bottom: 0.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #2a2a35;
}
body.mode-high-clarity table.clarity-tabular tbody td[data-label]::before {
    content: attr(data-label) ":";
    display: inline-block;
    min-width: 9ch;
    color: #9494a5;
    font-weight: 500;
    margin-right: 0.6rem;
    text-transform: none;
    letter-spacing: 0;
}
```

The `clarity-tabular` opt-in matters. Don't apply this to every
table indiscriminately, only ones where the column-label-as-prefix
pattern reads naturally. Convert your widget tables once (add the
class to `<table>` and `data-label="..."` to each `<td>`); the high
step picks them up automatically.

### Symptom → which step helps

| Symptom                | Clear  | High   |
| ---------------------- | ------ | ------ |
| Eye strain / migraine  | yes    | yes    |
| Vertical diplopia      | partial (cell padding) | yes (cards) |
| Horizontal diplopia    | partial (text size)    | yes (single col) |
| Rotational tilt        | yes    | yes    |
| 200% browser zoom      | yes    | yes    |
| Genuine low vision     | partial | yes   |

## Implementation pattern

The dial is a single string column on the user, a body class on
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
public const DISPLAY_CLEAR        = 'clear';
public const DISPLAY_HIGH_CLARITY = 'high_clarity';

public const DISPLAY_MODES = [
    self::DISPLAY_STANDARD,
    self::DISPLAY_CLEAR,
    self::DISPLAY_HIGH_CLARITY,
];

protected $fillable = [..., 'display_mode'];
```

`varchar(32)` not enum: lets you add `high_contrast`, `compact`,
or whatever else later without a schema change.

### 2. Body class

```php
@php
    $displayMode = auth()->user()?->display_mode ?? \App\Models\User::DISPLAY_STANDARD;
    $bodyMode = match ($displayMode) {
        \App\Models\User::DISPLAY_HIGH_CLARITY => 'mode-high-clarity',
        \App\Models\User::DISPLAY_CLEAR        => 'mode-clear',
        default                                => 'mode-standard',
    };
@endphp
<body class="... {{ $bodyMode }}" ...>
```

Every override in the CSS layer is scoped to `body.mode-clear` and
/or `body.mode-high-clarity`. Standard mode has no overrides at
all, so the no-op cost is zero.

### 3. Persistence endpoint

```php
// Routes
Route::post('/preferences/display', [PreferencesController::class, 'display'])
    ->name('preferences.display');

// Controller
public function display(Request $request): RedirectResponse
{
    $data = $request->validate([
        'display_mode' => ['required', Rule::in(User::DISPLAY_MODES)],
    ]);

    $request->user()->forceFill(['display_mode' => $data['display_mode']])->save();

    return redirect()->back();
}
```

Plain HTML form. No fetch, no Ajax, no JS framework. Submitting
re-renders the page in the new mode immediately.

### 4. Segmented-control UI

In the sidebar footer or header strip:

```blade
@php
    $clarityOptions = [
        \App\Models\User::DISPLAY_STANDARD     => ['label' => 'Standard', 'hint' => 'Default dashboard, no overrides.'],
        \App\Models\User::DISPLAY_CLEAR        => ['label' => 'Clear',    'hint' => 'Bigger text, more spacing, no motion.'],
        \App\Models\User::DISPLAY_HIGH_CLARITY => ['label' => 'High',     'hint' => 'Single column, tables become stacked cards.'],
    ];
@endphp
<div>
    <div class="text-[10px] uppercase tracking-wider text-muted/60 mb-1">View clarity</div>
    <div class="flex border border-line rounded overflow-hidden" role="group" aria-label="View clarity">
        @foreach ($clarityOptions as $value => $opt)
            @php $active = $displayMode === $value; @endphp
            <form method="POST" action="{{ route('preferences.display') }}" class="flex-1">
                @csrf
                <input type="hidden" name="display_mode" value="{{ $value }}">
                <button type="submit"
                        title="{{ $opt['hint'] }}"
                        aria-pressed="{{ $active ? 'true' : 'false' }}"
                        class="w-full text-[11px] py-1.5 transition
                               {{ $active
                                   ? 'bg-accent/20 text-ink font-medium'
                                   : 'text-muted hover:text-ink hover:bg-line/40' }}">
                    {{ $opt['label'] }}
                </button>
            </form>
        @endforeach
    </div>
</div>
```

Three forms, one button each. JS-free. The active step is visually
highlighted and `aria-pressed="true"` for screen readers. Clicking
any step immediately POSTs and reloads.

### 5. Tests worth having

```php
it('defaults new users to standard display mode', ...);
it('persists each of the three valid display modes', ...);  // dataset
it('rejects an unknown display_mode value', ...);
it('requires authentication', ...);
it('renders the body with the right mode-* class for each pref', ...);  // dataset
it('renders three segmented-control buttons in the sidebar footer', ...);
it('marks the active clarity step with aria-pressed=true', ...);
```

Cheap to write, catch regressions when someone refactors the
layout or adds a new mode value.

## UX rules (don't violate these)

1. **Universal access.** The dial is visible to every signed-in
   user. Not gated to one role, not hidden behind a settings page.
   Sidebar footer or always-visible header dropdown.
2. **Persists immediately.** One click, page reloads in the new
   mode. No "Save preferences" button.
3. **Per-user, not per-device.** Stored on the user, follows them
   across browsers and machines. Don't use localStorage.
4. **Defaults to standard.** Even if you personally think Clear is
   better, the default has to be the current experience so nothing
   changes for existing users on rollout.
5. **No confirmation dialog on switch.** This is a preference, not
   a destructive action.
6. **Active step is unmistakeable.** Use both colour AND weight to
   indicate which step is selected. Colour alone fails for users
   with colour-blindness; weight alone is too subtle.
7. **Each label maps to a perceivable difference.** If the user
   can't see a difference between two adjacent steps, you have one
   step too many. Three is usually right; four or five is usually
   wrong.

## Carry-over checklist for a new project

When you're scaffolding the same dial somewhere else, in order:

- [ ] Add `users.display_mode varchar(32) default 'standard'`.
- [ ] Add the three constants + `DISPLAY_MODES` array on User + add
      `display_mode` to fillable.
- [ ] Build the `PreferencesController@display` endpoint behind auth.
- [ ] Wire the body class match expression in the layout.
- [ ] Drop the CSS layer (both layers) from this guide into the
      layout's `<style>` block (or a dedicated CSS file if you have
      a build step).
- [ ] Add the segmented-control form somewhere always-visible
      (sidebar footer is the obvious spot).
- [ ] For each widget table you want to opt into the High-step
      stacked-card render: add `class="clarity-tabular"` and
      `data-label="..."` on each `<td>` (the first cell in each row
      should have NO data-label so it acts as the card heading).
- [ ] Write the seven tests above.
- [ ] Try it on actual data with someone who'd benefit. Watch them
      step through Standard → Clear → High. If they can't perceive
      the jump from Standard to Clear, your typography nudge is too
      weak. If they overshoot to High and bounce back to Standard,
      the High step is too aggressive.

That's the whole job. Half a day to a day of work for a real win.

## Things to leave for "phase 2" if a real user asks

These have a place but are not load-bearing for a v1:

- **More than three steps.** Resist the urge to add intermediate
  positions. Three is enough to cover Standard / mild help /
  serious help. Adding a fourth means none of them are perceivably
  different from their neighbours.
- **Density preference** (compact / cosy / comfortable). Different
  axis from clarity. Padding-heavy, not contrast-heavy.
- **Theme preference** (e.g. brand colours vs default). Orthogonal
  again. Use a separate column, separate body class.
- **Per-widget show/hide / drag-to-reorder.** Real customisation.
  Adds persistence + JS state. Wait for a complaint.
- **Chart-as-list fallback.** If a user can't read your charts, give
  each chart a "Show as list" toggle that renders the same data as
  a labelled card stack. Genuinely useful, low priority until asked.
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
  Standard mode.

## What this guide is not

- A WCAG compliance checklist. WCAG is broader (keyboard nav, ARIA,
  colour contrast tokens, focus states). This guide covers one
  specific axis (display mode for visual fatigue / diplopia). The
  two overlap but aren't the same.
- A design system. The CSS layer here is a thin override on top of
  whatever you already have.
- A justification for skipping an accessibility audit. If your tool
  is internal-only and you have one user with a known need, this
  pattern is enough. If you ship to the public, get a real audit.
