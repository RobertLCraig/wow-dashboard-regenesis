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
- [ ] #1 WHEN the style check is run the way this repository documents it, and any tracked PHP file
      is not Pint-clean, IT SHALL exit non-zero.
      proves: manual - the check is a shell exit code, and this suite cannot run Pint against itself
      without shelling out to the tool it is testing
- [ ] #2 WHERE this repository tells a reader how to run the style check, IT SHALL name the form
      that actually scans files.
      proves: none - a document's content, which no test in this suite can read
<!-- AC:END -->

## Tasks
- [ ] Settle whether the fix is a `pint.json` naming the paths, a composer script, or documenting
      the dot, and say which and why in `## Comments`
- [ ] Apply it, and prove it by running the check against the dirty file with `0014` still open
- [ ] Update the command in `README.md` and in `docs/HANDOVER.md` to the fixed form

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
