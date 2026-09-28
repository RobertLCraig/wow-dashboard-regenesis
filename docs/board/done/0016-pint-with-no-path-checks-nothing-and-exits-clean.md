# Pint with no path checks nothing and reports clean

## Why
The style step every card session is handed is `.\vendor\bin\pint.bat`. Run with no path argument in
this repository, it exits **0** and reports `passed`. Add a dot, `.\vendor\bin\pint.bat --test .`,
and the same tree exits **1** and names `app/Services/Raiderio/RaiderioSnapshotImporter.php` with six
fixers. Nothing about the tree changed between the two runs.

Measured on 2026-09-05, on a clean checkout of `card/0015`, one spelling of the root path per run:

| What was passed | Exit |
|---|---|
| nothing (Pint defaults to the working directory) | **0** |
| `C:\Users\r\...\Regenesis` | **0** |
| `C:\Users\r\...\Regenesis\` (trailing separator) | 1 |
| `.` | 1 |
| `./` | 1 |
| `.\` | 1 |
| `app` | 1 |

So one form is wrong and every other form is right, and the wrong one is the form with no argument:
an absolute path to the root carrying no trailing separator matches zero files, and Pint calls a
scan of nothing a pass.

**What it costs.** A green style step that inspected no files is worse than no style step, because
the session records it as a check that passed. That already happened: card `0014` reformatted 177
files, ticked a criterion reading "Pint exits 0", and shipped one file still dirty. Two reviewers
caught it afterwards by running Pint against a path.

**How it came to be this way.** Nobody chose the bare form. It is what a person types, so it is what
went into the instructions every session receives, and it had never been checked against a
deliberately dirty file.

## Links

**Relates to**
- `0014` - it ticked its acceptance on the bare form and shipped one file dirty, which is the
  failure this card removes rather than the file, and that file is still `0014`'s to fix.
- `0015` - wrote the trap into `docs/HANDOVER.md` as a workaround, because a document was all that
  card was allowed to change.

## Not this card
**Fixing `RaiderioSnapshotImporter.php`.** It is dirty, and it belongs to `0014`. Reformatting it
here would remove the only offender this card can test against.

**Changing what sessions are told to run.** The card-session prompt lives in ProgressBoard, not in
this repository. This card makes the command in this repository honest; whether the prompt should
quote it is somebody else's.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the style check is run the way this repository documents it, and any tracked PHP file
      is not Pint-clean, IT SHALL exit non-zero.
      proves: manual - the check is a shell exit code, and this suite cannot run Pint against itself
      without shelling out to the tool it is testing
- [x] #2 WHERE this repository tells a reader how to run the style check, IT SHALL name the form
      that actually scans files.
      proves: none - a document's content, which no test in this suite can read
<!-- AC:END -->

## Tasks
- [x] Settle whether the fix is a `pint.json` naming the paths, a composer script, or documenting
      the dot, and say which and why in `## Comments`
- [x] Apply it, and prove it by running the check against the dirty file with `0014` still open
- [x] Update the command in `README.md` and in `docs/HANDOVER.md` to the fixed form

## Plan
**Where to stand.** This repository, from its root, in PowerShell. PHP comes from Laravel Herd and is
not on the Git Bash PATH.

**Reproduce it first, and it takes one line.** With `app/Services/Raiderio/RaiderioSnapshotImporter.php`
still dirty:

```powershell
.\vendor\bin\pint.bat --test    ; "no path  => $LASTEXITCODE"   # prints 0
.\vendor\bin\pint.bat --test .  ; "dot      => $LASTEXITCODE"   # prints 1
```

Both lines printing the same number means `0014` has been fixed and the offender is gone. Make a
throwaway file dirty (badly ordered imports will do), reproduce, and delete it. Do not commit it.

**The likely fix, and it is the smallest one.** Laravel Pint reads `pint.json` from the working
directory if there is one. A `pint.json` with an explicit preset and an explicit list of paths to
scan removes the guess entirely, and it is one file with no code in it. Check the version in
`composer.lock` (`laravel/pint`, currently `^1.24`) against Pint's own documentation before assuming
the key names.

**What "it worked" looks like:** the command as `README.md` prints it exits 1 against a dirty tree
and 0 against a clean one, and a reader who copies it out of the README gets the honest answer
without knowing any of this.

## Comments

**2026-09-05** RESULT: done
TESTS: +0 new, all green (748 passed). Both criteria are `proves: manual` / `proves: none`, so
neither gets a Pest test; the manual proof is transcribed below.
TOUCHED: pint.json (new), .gitignore, README.md, docs/HANDOVER.md,
docs/board/todo/0017-readme-says-the-suite-is-45-tests-and-it-is-748.md (new),
docs/board/in-progress/0016-pint-with-no-path-checks-nothing-and-exits-clean.md
OUT-OF-SCOPE: 0017

**The card's diagnosis was wrong, and the real cause is worse.** `## Why` says the bare form
"matches zero files". It does not. Pint scans the whole tree on every spelling, including the bare
one. What differed between the spellings was Pint's **result cache**.

Read from the shipped phar, `app/Factories/ConfigurationResolverFactory.php`, Pint 1.24 /
php-cs-fixer 3.95.1: when no `cache-file` is configured, Pint puts its cache at

    <system temp dir>/md5(implode('|', $paths))

where `$paths` is the path arguments **exactly as typed**. Three things follow, and each one is the
fault on its own:

1. **Every spelling gets its own cache**, so `pint --test` and `pint --test .` are answering from
   different records of the same tree. That alone explains the table in `## Why`.
2. **The cache is outside the repository.** It survives a branch switch, a `git clean`, and the
   destruction and recreation of a whole worktree, because the key is the path string and the card
   worktree is recreated at the same path every time. A verdict from one card's tree is served to
   the next card's tree.
3. **A stale entry is a silent skip, not a warning.** `FileFilterIterator` drops any file whose
   content hash matches its cache entry before a fixer ever sees it, and Pint then prints
   `passed`.

**Reproduced deliberately, not just observed.** With `RaiderioSnapshotImporter.php` dirty
(`0014`'s) I added a second deliberately dirty file, `app/ZzPintProbe.php` (unordered imports, brace
on the wrong line, spaces inside parentheses), confirmed `pint --test .` named both, then wrote a
cache file at `<temp>/md5(<repo root>)` holding those two files' on-disk `xxh128` hashes and ran the
bare form:

    .\vendor\bin\pint.bat --test    =>  {"tool":"pint","result":"passed"}   exit 0
    .\vendor\bin\pint.bat --test    =>  {"tool":"pint","result":"passed"}   exit 0   (stable, not a one-off)

That is the red. Two dirty files on disk, a green style check, exit 0. It is spelling-independent: I
could have poisoned the `.` cache just as easily, so "always pass the dot" was never a fix.

**The fix, and why this one.** A `pint.json` with a single key:

    { "cache-file": ".pint.cache" }

- **Not a `pint.json` naming paths**, which is what `## Plan` guessed. I checked
  `app/Repositories/ConfigurationJsonRepository.php` in the phar: the only keys Pint reads are
  `preset`, `rules`, `exclude`, `notPath`, `notName`, `cache-file` and `extend`. There is no key for
  the paths to scan, so that fix does not exist.
- **Not a composer script.** A `composer lint` wrapper would still have used the shared temp cache,
  so it would have inherited the whole fault while looking like a fix.
- **Not documenting the dot.** The dot never fixed anything, as above.
- **No `preset` key**, deliberately. `laravel` is already Pint's default, and pinning a value
  nobody chose is a behaviour lock no card decided.

`.pint.cache` is gitignored. It is now per checkout, thrown away with the worktree, and identical
for every spelling of the argument.

**Green, on the same tree, with the poisoned temp cache still in place.** Every form now agrees:

    (no path)   => 1        .            => 1
    <abs root>  => 1        ./           => 1
    <abs root>\ => 1        app          => 1

and it still exits 0 where the tree really is clean (`pint --test tests` and `pint --test config`
both exit 0). The probe file and the poisoned cache were deleted; nothing from the reproduction is
committed. `git status` after the work is the four files on TOUCHED and nothing else.

**What I could not settle from the repository.** Which past Pint run wrote the false entry into
`<temp>/md5(<repo root>)`. I deleted that file while narrowing the cause and before I understood
what it held, so the evidence is gone. Dry-run cannot write a false entry — `FileCacheManager::
setFileHash` clears an entry rather than overwriting it when the hash disagrees and `isDryRun` is
true — so it was written by a fix-mode run, and beyond that I would be guessing. It does not change
the fix: the reproduction above shows the mechanism is reachable regardless of which run took it.

**Left alone on purpose.** `app/Services/Raiderio/RaiderioSnapshotImporter.php` is still dirty, per
`## Not this card`. `.\vendor\bin\pint.bat --test` therefore exits 1 on this branch, which is the
correct answer and is the evidence that criterion #1 holds. It is `0014`'s to fix.

### 2026-09-05 review (v20260905064827-aff6)

**suite**

`vendor\bin\pest.bat` exited 0 after 23s, run by this job rather than reported by the card.

**acceptance: sound**

**Criterion #1 ÔÇö traced.**
`pint.json` sets `cache-file` to `.pint.cache`. That key is real, not invented: `ConfigurationJsonRepository::cacheFile()` in the shipped phar (`vendor/laravel/pint/builds/pint`) reads it, and `ConfigurationResolverFactory::fromIO()` consumes it in the exact expression the card blames ÔÇö `$input->getOption('cache-file') ?? $localConfiguration->cacheFile() ?? <temp>/md5(implode('|', $path))`. The config now short-circuits the temp-dir-keyed-by-path-string branch before it is reached. One cache per checkout, shared by every spelling. `.gitignore` has `/.pint.cache`, root-anchored.

The comment's side claim also checks out: `ConfigurationJsonRepository::$finderOptions` plus its other getters expose only `exclude`, `notPath`, `notName`, `rules`, `preset`, `cache-file`, `extend`. There is no key for scan paths, so `## Plan`'s guessed fix genuinely does not exist.

**Criterion #2 ÔÇö traced.**
`README.md` "Useful commands" and `docs/HANDOVER.md` "How to pick up" both now print the bare `--test` form. A repo-wide grep for `pint` finds no other place that instructs a reader: no composer lint script, no CI workflow.

**Limit I hit:** I am read-only, so I confirmed #1 by mechanism in Pint's source, not by running the exit code myself.

VERDICT: sound

**scope: defect**

**Fence: respected.** `app/Services/Raiderio/RaiderioSnapshotImporter.php` untouched. No ProgressBoard edit. The probe file and poisoned cache are gone; `git status` is clean. Raising `0017` matches existing practice (`0013` raised `0014` the same way), so that is not creep.

**Grew past the task ÔÇö `docs/HANDOVER.md`, `## Current state`.** Task 3 scoped this file to "the command". The In-progress bullet also gained "`0016` (the Pint false clean) is built and awaiting review; it added `pint.json`, and `0017` was raised off it". That is the card writing its own lane status into a document whose stated split is that `docs/board/` holds what is moving. It becomes false the moment this review moves the card.

**Left half done ÔÇö `README.md`, `## Useful commands`.** The new line is `vendor/bin/pint --test`. Everywhere else this repository documents the check ÔÇö `docs/HANDOVER.md`, `## How to pick up`, and `## Plan` on this card ÔÇö the form is `.\vendor\bin\pint.bat`, because the documented shell is PowerShell and PHP comes from Herd. `vendor/bin/pint` is the extensionless shebang script; PowerShell will not run it. So the two documents a reader is pointed at name different forms, and the README one errors instead of answering. `## Plan` asked for the opposite: a reader who copies it out of the README gets the honest answer.

VERDICT: defect

**breakage: sound**

I tried to break it. I could not.

**No caller was left behind.** There is no `.github/`, and `composer.json` `scripts` has no style entry. `README.md` and `docs/HANDOVER.md` are the only places that name the command, and both changed.

**The key is real in the version actually installed** (Pint 1.29.1, not the 1.24 the comment names): `App\Repositories\ConfigurationJsonRepository::cacheFile` reads `cache-file`, and `App\Factories\ConfigurationResolverFactory::fromIO` prefers it over the temp-dir default.

**Adding `pint.json` changes nothing else.** `ConfigurationJsonRepository::preset` still returns `laravel`, and `ConfigurationFactory::finder` keeps its own `exclude` and `notName` defaults, because the file names no finder key.

**The cache file cannot poison the scan or the tree.** `ConfigurationFactory::finder` calls `ignoreDotFiles(true)`, the `.gitignore` entry is root-anchored where the file lands, and `deploy.ps1`'s `git status --porcelain` gate cannot see an ignored file.

**The one hole the docs already close.** `App\Providers\RepositoriesServiceProvider::register` reads `Project::path().'/pint.json'`, and `App\Project::path` is `getcwd()`, so the fix is inactive from any other directory. `docs/HANDOVER.md` "How to pick up" says "in the repository root" directly above the command.

VERDICT: sound


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 2 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 2 of 2 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: sent to `ai-review/`, because the scope finding is wrong. It says the
README's `vendor/bin/pint --test` will not run in PowerShell. But that line sits in the README's
`## Useful commands` block, which is fenced as ```` ```sh ````, and every command in the block is in
shell form. In a shell, `vendor/bin/pint` is the right form. `docs/HANDOVER.md`, `## How to pick up`,
gives the PowerShell form, `.\vendor\bin\pint.bat --test`. So both documents name a form that scans
files, which is what #2 asks. #1 was checked by running it today: from the repository root the bare
`--test` form exits 1 and names `RaiderioSnapshotImporter.php`, the dirty file `0014` still owes, so
it no longer reports clean on a dirty tree. The acceptance and breakage lenses both found this card
sound. The `## Current state` line in the handover is lane bookkeeping and stops being true when the
card moves, but no criterion covers it.

### 2026-09-28 review (v20260928191942-0aa2)

**suite**

`vendor\bin\pest.bat` exited 0 after 71s, run by this job rather than reported by the card.

**acceptance: sound**

**Criterion #1: met.**
- The fix is in `pint.json`. It sets `cache-file` to `.pint.cache`.
- Pint reads that key in `ConfigurationJsonRepository::cacheFile`. It is inside the Pint program file in `vendor`.
- `ConfigurationResolverFactory::fromIO` then uses that file instead of the shared temp cache. The old temp cache used a name made from the path you typed, so each spelling got its own record of the tree. Now every spelling uses one cache in the repo.
- `.gitignore` excludes `/.pint.cache`.
- On 2026-09-28 the manager ran the bare `--test` form. It exited 1 and named `RaiderioSnapshotImporter.php`, the dirty file that card `0014` still owes.
- I only read files, so I did not run it myself.

**Criterion #2: met.**
- `docs/HANDOVER.md`, "How to pick up", gives the PowerShell form: `.\vendor\bin\pint.bat --test`.
- `README.md`, "Useful commands", gives `vendor/bin/pint --test`. That block is fenced as `sh`, and every command in it is shell form. So the README is correct for its shell.
- Neither document tells the reader to pass a dot any more.
- No other file tells a reader how to run the check. `composer.json` has no lint script and there is no CI.

I could not disprove either criterion.

VERDICT: sound

**scope: sound**

**Scope review: card 0016 (Pint false clean)**

Card 0016 did not go past its fence. It left no task half done.

- **The work fits the card.** It changed `pint.json` (a new file), `.gitignore`, `README.md` ("Useful commands") and `docs/HANDOVER.md` ("How to pick up"). These match the card's TOUCHED list. The diff also shows `WeeklyDigestBuilder::database()`, `config/snapshots.php`, `docs/ops-runbook.md` and moves of other cards. Those changes come from other cards (0002, 0004 and the manager pass), not from 0016. The diff starts at the wrong point, so it shows them together.
- **The fence holds.** The card did not touch `RaiderioSnapshotImporter.php`. It made no change in ProgressBoard.
- **The README line is correct.** The earlier review said PowerShell cannot run `vendor/bin/pint --test`. But that line is inside a ```` ```sh ```` block, and every command in that block is in shell form. `docs/HANDOVER.md` "How to pick up" gives the PowerShell form, `.\vendor\bin\pint.bat --test`. So each document names a form that scans the files.
- **One extra line, small.** Under `## Current state`, `docs/HANDOVER.md` now says "`0016` ... is built and awaiting review". That line is lane status, and the card did not ask for it. Today it is true. It will be false when the card moves. No criterion covers it, so it does not disprove #1 or #2. The person who moves the card can delete it.

No criterion is disproved.

VERDICT: sound

**breakage: sound**

I tried to break this card, and I could not. The verdict is **sound**.

**No caller was left behind.** `pint.json` has one key, `cache-file`. Only two documents tell a reader how to run Pint: `README.md` (`## Useful commands`) and `docs/HANDOVER.md` (`## How to pick up`). The card changed both. `composer.json` has no lint script. There is no CI workflow.

**The fix works on every spelling of the root path.** Pint's `ConfigurationResolverFactory::fromIO` uses `cache-file` from `pint.json` before its default. The default is a temp-folder cache named from the path you typed. So the bare form and `.` now read and write the same cache in the repository root. `.gitignore` ignores `/.pint.cache`, so `deploy.ps1`'s clean-tree gate does not see it.

**The docs are still true.** The README block is shell (`sh`), so `vendor/bin/pint` is the right form there. The handover gives the PowerShell form, `.\vendor\bin\pint.bat`. One small point: the handover says "any path argument, or none, gives the same answer". That is loose, because a narrower path such as `tests` checks fewer files. It is still true for every spelling of the root, and no criterion depends on it.

**One weak spot, which the docs already cover.** Pint reads `pint.json` from the current folder only. The handover says to run the command from the repository root.

VERDICT: sound

