# The Social calendar's event colours were changed by a card that did not ask for it

## Why
Card 0006's first build (commit `23be726`) rewrote `$eventToneClasses` in the grid branch of
`resources/views/dashboard/social.blade.php`: all three tones moved shade and opacity, for example
`bg-sky-900/40 text-sky-200 border-sky-800/60` became `bg-sky-900/60 text-sky-100 border-sky-700/70`.
0006 asked for bar shape, not a repaint, and no comment on it gives a reason. Two reviews of 0006
flagged it as over the fence, and it was never reverted or carried, so it sits on `main` as an
unexplained design change.

## Links

**Relates to**
- `0006` - the multi-day bars rewrite that made the change.

## Not this card
Bar shape, lanes, the tooltip (`0021`) or the lane comment (`0022`). The list view's `$kindBadge`
tones, which 0006 did not touch.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE `$eventToneClasses` map in the grid branch of `social.blade.php` SHALL hold the values
      it had before `23be726`: `bg-sky-900/40 text-sky-200 border-sky-800/60`,
      `bg-violet-900/40 text-violet-200 border-violet-800/60`,
      `bg-amber-900/40 text-amber-200 border-amber-800/60`; UNLESS those fail the AA text ratio on
      a bar drawn over the day-cell backdrop, in which case the current values SHALL stay and this
      card's Comments SHALL record the measured ratios that justify them.
      proves: the ratio of each tone's text over its bar, computed from the Tailwind palette values
      composited over the page ground and recorded in Comments
<!-- AC:END -->

## Tasks
- [ ] Measure text-on-bar contrast for the three pre-`23be726` tones as bars now render
- [ ] Revert to them if they clear AA, otherwise keep the current tones and record the numbers

## Plan
`git show 23be726^:resources/views/dashboard/social.blade.php` holds the old map. The bars now sit
over a day-cell backdrop rather than inside a cell, so the ground under the text may differ from
what the old chips had; that is the only reason the new values could be needed, and the one to check.

## Comments

**2026-09-29** Raised by an attended unblock pass from 0006's 2026-09-28 scope review, which said
the next session must either revert the colours or give them their own card with a reason.
