# README says the suite is 45 tests and it is 748

## Why
`README.md`, under `## Useful commands`, prints this line:

```sh
php artisan test                                # 45 Pest tests, ~2s
```

Run on 2026-09-05 in a clean worktree, the suite reports **748 passed (2353 assertions)** in about
**22 seconds**. So the comment is wrong by a factor of sixteen on the count and by an order of
magnitude on the time. `docs/HANDOVER.md` already carries the right numbers, which means the two
documents a reader is pointed at disagree with each other.

**What it costs.** Small, but it is the one number a reader uses to decide whether a run has gone
wrong. Somebody who reads "45, ~2s" and then watches a 22-second run has to stop and work out
whether they have broken something, and somebody who sees a truncated run of 45 tests has lost the
signal that would have told them.

**How it came to be this way.** The comment was true when it was written and nothing updates it. No
card touched it, because it sits in a code block nobody had a reason to open.

## Links

**Relates to**
- `0016` - found while editing the same code block to add the style command, and left alone there
  because fixing it in passing would have been unreviewed work.

## Not this card
**Every other number in `README.md`.** Only this one was measured. Auditing the rest of the document
for staleness is a different and much larger job.

**Making the number update itself.** A generated count needs a generator and something to run it,
and that is a bigger change than this fault justifies.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHERE `README.md` states the size and duration of the test suite, IT SHALL match what
      `.\vendor\bin\pest.bat` prints on a clean checkout.
      proves: none - a document's content, which no test in this suite can read
<!-- AC:END -->

## Tasks
- [ ] Run `.\vendor\bin\pest.bat` from the repository root and read the summary line it prints
- [ ] Put that count and that duration into the comment in `README.md`, rounding the duration the
      way `docs/HANDOVER.md` already does

## Plan
**Where to stand.** This repository, from its root, in PowerShell. PHP comes from Laravel Herd and
is not on the Git Bash PATH.

One command gives the answer:

```powershell
.\vendor\bin\pest.bat
```

The last two lines it prints are the count and the duration. The line to change is in `README.md`
under `## Useful commands`. `docs/HANDOVER.md` under `## How to pick up` already phrases the same
fact as "748 tests, about 26s, sqlite in memory"; match that phrasing so the two agree.

**What "it worked" looks like:** a reader comparing the README comment with a real run sees the same
number.

## Comments
