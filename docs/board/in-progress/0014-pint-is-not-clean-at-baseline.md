# Pint is not clean at baseline, so the style step cannot be run honestly

## Why
Running `.\vendor\bin\pint.bat` on a clean checkout rewrites **177 files** that the current work
never touched: 121 under `app/`, 49 under `tests/`, and 7 across `config/`, `database/`, `routes/`
and `tools/`. Measured on 2026-09-05 with `pint --test`, which exits 1. The fixers are ordinary
preset rules - `concat_space`, `blank_line_before_statement`, `ordered_imports`,
`unary_operator_spaces`, `not_operator_with_successor_space` - so this is drift, not a
misconfiguration of one rule.

**What it costs.** Every card is required to run the style step before committing, and every card
that has tried has had to work around it. Five build sessions in a row hit the same wall: each ran
Pint bare, saw ~180 unrelated files rewritten, ran `git checkout` to put them back, and committed
with only `--dirty` checked. That is five sessions spending time on the same discovery, and it means
the repository's style gate has never once been passed as written. It also hides real drift: a
genuinely misformatted new file is one line in a list of 177.

**How it came to be this way.** Pint was added to `composer.json` but never run over the code that
was already there, so the baseline was non-conforming from the first commit and every file written
since has been written against its neighbours rather than against the preset.

## Links

**Relates to**
- `0004` - its 2026-08-29 comment is where this was first written down, in the words "worth a card
  of its own - either adopt the reformat in one commit or pin the Pint preset - but it is not this
  one". Four other cards recorded the same thing and no card ever carried it.

## Not this card
**Changing what the code does.** This is a formatting pass and nothing else. A Pint run that alters
behaviour is a bug in Pint, and any file where the reformat looks like more than whitespace, import
order and quoting is one to leave alone and name here rather than to hand-edit.

**Adding a pre-commit hook or a CI check.** Making the tree clean is what this card is for. Keeping
it clean is a separate call about tooling, and it has no value until the tree is clean once.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN `.\vendor\bin\pint.bat --test` is run from the repository root, IT SHALL exit 0.
      proves: none - the check is Pint's own exit code, and no test in this suite runs Pint
- [ ] #2 THE REFORMAT SHALL be one commit of its own, touching no file for any other reason, so a
      later `git blame` can skip it in one step. proves: none - a property of the commit, which the
      suite cannot see
- [ ] #3 WHEN the full suite is run after the reformat, IT SHALL pass with the same number of tests
      as before it. proves: none - the whole suite is the check, and it has no name of its own
<!-- AC:END -->

## Tasks
- [ ] Record the test count and the `pint --test` file count before touching anything
- [ ] Run `.\vendor\bin\pint.bat` and commit the result on its own, with no other change in it
- [ ] Re-run the suite and confirm the count matches the one recorded above

## Plan
**Where to stand.** This repository, on whatever branch the session was given, from the repository
root in PowerShell. PHP comes from Laravel Herd and is not on the Git Bash PATH, so run both of
these from PowerShell and not from Bash:

    .\vendor\bin\pint.bat --test
    .\vendor\bin\pest.bat

**Do the suite first and write the number down.** A reformat of 177 files that also breaks a test is
indistinguishable from a reformat that breaks nothing unless the before-count is on the card. Put it
in `## Comments` before running Pint, not after.

**Nothing here decides the preset.** `pint.json` does not exist in this repository, so Pint is using
its default `laravel` preset, and this card adopts that preset rather than choosing one. If the
reformat turns out to disagree with a house habit often enough to matter, say so in `## Comments`
and leave it: pinning a preset is a decision with a cost, and it is not this card's to take.

## Comments

**2026-09-05** BEFORE-COUNTS, recorded before Pint was run over anything.

    .\vendor\bin\pest.bat        748 passed, 2353 assertions, exit 0
    .\vendor\bin\pint.bat --test 177 files, exit 1

The 177 split by top folder: `app` 121, `tests` 49, `config` 3, `tools` 2, `database` 1, `routes` 1.
That is the card's own count reproduced unchanged, so nothing drifted between the card being written
and this session picking it up.
