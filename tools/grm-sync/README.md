# grm-sync

PowerShell tool that pushes the GRM addon's SavedVariables to the
Regenesis dashboard on a schedule.

## What it does

1. Picks the freshest `Guild_Roster_Manager.lua` across every WoW account
   folder under `C:\Games\World of Warcraft\_retail_\WTF\Account\*\SavedVariables\`.
2. Copies it to `%TEMP%` (avoids the WoW client's write lock).
3. Runs [`extract.php`](extract.php) which uses
   [`App\Services\Grm\LuaTableParser`](../../app/Services/Grm/LuaTableParser.php)
   to turn the four globals we care about (current members, former
   members, log report, alts) into a single JSON envelope.
4. SHA-256s the JSON. Skips the upload if the hash matches the last
   successful run.
5. Gzips and POSTs to `https://regenesis.enhanceify.co.uk/api/ingest/grm`
   with a bearer token.
6. Stores the new hash on success. Logs every run to
   `%LOCALAPPDATA%\regenesis-grm\grm-sync.log`.

## Install on a fresh PC

Prerequisites: a clone of this repo at `C:\Dev\Regenesis`, PHP 8.2+ on
PATH (Herd's `php84` works), and PowerShell 5+ (`pwsh` ideally).

```cmd
cd C:\Dev\Regenesis\tools\grm-sync
setup-grm-sync.bat
```

The installer prompts for the ingest URL + bearer token (from the Laravel
app's `.env` `GRM_INGEST_TOKEN`), writes them to
`%LOCALAPPDATA%\regenesis-grm\config.json`, and registers the
`RegenesisGrmSync` scheduled task that fires every 30 minutes (and at
logon).

## Manual triggers

```powershell
# One-off sync, with logging:
pwsh tools\grm-sync\grm-sync.ps1 -Verbose

# Force-upload even if the data hasn't changed:
pwsh tools\grm-sync\grm-sync.ps1 -Force

# Extract + hash but don't POST:
pwsh tools\grm-sync\grm-sync.ps1 -DryRun

# Trigger via Task Scheduler (runs the silent .vbs wrapper):
schtasks /Run /TN RegenesisGrmSync
```

## Files

| File | What |
|------|------|
| `extract.php` | PHP CLI: parses .lua → JSON envelope to stdout |
| `grm-sync.ps1` | PowerShell driver: orchestrates extract + hash + POST |
| `grm-sync.vbs` | Silent wrapper called by Task Scheduler |
| `GrmSync-Task.xml` | Task Scheduler import, mirrors `syncToOneDrive` shape |
| `setup-grm-sync.bat` | One-shot installer: writes config + registers task |

## Why PHP, not lua54.exe

The plan originally called for vendoring `lua54.exe`. We pivoted to a
focused PHP parser because (a) the user's PC already has PHP via Herd,
(b) GRM's SavedVariables is data-only Lua so a tailored parser is
~250 lines and reliable, and (c) no extra binary to install/maintain on
each PC. The "PHP parsing is brittle" warning in the plan applied to
*server-side* parsing under Hostinger's 30s/256MB limits, not local CLI
runs. See `~/.claude/projects/c--Dev-Regenesis/memory/feedback_grm_parser.md`.

Smoke-tested against a real 3.7MB file: 0.49s parse, 30MB peak memory,
parsed 783 current members + 128 former + 1880 log entries + 188 alt
groups cleanly.
