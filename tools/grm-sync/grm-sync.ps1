# ─────────────────────────────────────────────────────────────────────────────
#  Regenesis grm-sync — local PowerShell driver
#
#  Picks the most recently-modified Guild_Roster_Manager.lua across every
#  WoW account folder, parses it via tools/grm-sync/extract.php, gzips
#  the JSON, and POSTs it to the dashboard's /api/ingest/grm endpoint.
#
#  Designed to mirror the C:\Dev\syncToOneDrive shape so the Task
#  Scheduler XML can be near-identical.
#
#  Usage:
#    pwsh ./grm-sync.ps1                    # one-shot sync
#    pwsh ./grm-sync.ps1 -Force             # skip the unchanged-hash check
#    pwsh ./grm-sync.ps1 -DryRun            # extract + hash, do not POST
#    pwsh ./grm-sync.ps1 -Verbose           # log every step
# ─────────────────────────────────────────────────────────────────────────────
[CmdletBinding()]
param(
    # Where the Regenesis Laravel repo lives. extract.php and Composer's
    # autoloader are resolved relative to this. Default assumes the repo
    # is cloned at C:\Dev\Regenesis (matches the user's other projects).
    [string]$RepoRoot = 'C:\Dev\Regenesis',

    # Direct path to php.exe. Default points at Herd's bundled PHP 8.4
    # (avoids the bash → cmd → php.bat wrapper that swallows stdout when
    # a script runs more than a fraction of a second).
    [string]$PhpExe = 'C:\Users\r\.config\herd\bin\php84\php.exe',

    # Where the GRM SavedVariables files live. We scan all account
    # folders (the user has 4+) and pick the freshest.
    [string]$WowAccountRoot = 'C:\Games\World of Warcraft\_retail_\WTF\Account',

    # Endpoint + bearer token for the Laravel ingest API. Defaults read
    # from %LOCALAPPDATA%\regenesis-grm\.config so the ps1 itself stays
    # clean of secrets — setup-grm-sync.bat populates that file.
    [string]$IngestUrl,
    [string]$IngestToken,

    # Force-upload even if the JSON hash matches the last successful POST.
    [switch]$Force,

    # Run the extract + hash check without uploading. Useful for smoke-test.
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

function Write-Step($Label, $Text) {
    if ($VerbosePreference -ne 'SilentlyContinue') {
        Write-Host "[$(Get-Date -Format HH:mm:ss)] $Label $Text"
    }
}

# ── Resolve config ──────────────────────────────────────────────────────────
$ConfigDir = Join-Path $env:LOCALAPPDATA 'regenesis-grm'
$ConfigFile = Join-Path $ConfigDir 'config.json'
$HashFile = Join-Path $ConfigDir 'last.sha256'
$LogFile = Join-Path $ConfigDir 'grm-sync.log'

if ((-not $IngestUrl -or -not $IngestToken) -and (Test-Path $ConfigFile)) {
    $cfg = Get-Content $ConfigFile -Raw | ConvertFrom-Json
    if (-not $IngestUrl) { $IngestUrl = $cfg.ingest_url }
    if (-not $IngestToken) { $IngestToken = $cfg.ingest_token }
}

if (-not $IngestUrl -or -not $IngestToken) {
    throw "IngestUrl + IngestToken not set. Run setup-grm-sync.bat first, or pass -IngestUrl / -IngestToken."
}

if (-not (Test-Path $ConfigDir)) { New-Item -ItemType Directory -Path $ConfigDir | Out-Null }

function Append-Log($message) {
    "$([DateTime]::UtcNow.ToString('o'))  $message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

# ── Pick the freshest SavedVariables file ──────────────────────────────────
Write-Step '[1/5]' "Locating GRM SavedVariables under $WowAccountRoot"

if (-not (Test-Path $WowAccountRoot)) {
    Append-Log "FATAL: WoW account root not found: $WowAccountRoot"
    throw "WoW account root not found: $WowAccountRoot"
}

# GRM writes TWO SavedVariables files per account:
#   <Account>\SavedVariables\Guild_Roster_Manager.lua  (account-wide, ~MB,
#       holds GRM_GuildMemberHistory_Save and friends — what we want)
#   <Account>\<Realm>\<Char>\SavedVariables\Guild_Roster_Manager.lua  (~KB,
#       per-character GRM_DebugLog_Save / GRM_MinimapPosition only)
# A naive recursive search picks both. Fix the depth at exactly
# Account\<id>\SavedVariables\ to skip the per-char companions.
$candidate = Get-ChildItem -Path (Join-Path $WowAccountRoot '*\SavedVariables\Guild_Roster_Manager.lua') -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1

if (-not $candidate) {
    Append-Log "FATAL: no account-wide Guild_Roster_Manager.lua found under $WowAccountRoot\*\SavedVariables\"
    throw "No Guild_Roster_Manager.lua found at <Account>\SavedVariables\. Make sure GRM is installed and you've logged in at least once since."
}

Write-Step '   ->' "$($candidate.FullName) ($([math]::Round($candidate.Length/1KB)) KB, modified $($candidate.LastWriteTime))"

# ── Copy aside (avoids file-lock if the WoW client is running) ─────────────
$tempLua = Join-Path $env:TEMP "grm-$([Guid]::NewGuid().ToString('N')).lua"
Copy-Item -LiteralPath $candidate.FullName -Destination $tempLua -Force
Write-Step '[2/5]' "Copied to $tempLua"

# ── Run extract.php ────────────────────────────────────────────────────────
$extractPhp = Join-Path $RepoRoot 'tools\grm-sync\extract.php'
if (-not (Test-Path $extractPhp)) {
    throw "extract.php missing at $extractPhp"
}
if (-not (Test-Path $PhpExe)) {
    throw "PHP not found at $PhpExe"
}

Write-Step '[3/5]' "Running extract.php"

# Capture stderr separately so a parse failure surfaces clearly. JSON on
# stdout, errors on stderr — the PHP CLI keeps these clean.
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = $PhpExe
$psi.Arguments = "-d memory_limit=512M `"$extractPhp`" `"$tempLua`""
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.UseShellExecute = $false
$psi.CreateNoWindow = $true
$proc = [System.Diagnostics.Process]::Start($psi)
$stdout = $proc.StandardOutput.ReadToEnd()
$stderr = $proc.StandardError.ReadToEnd()
$proc.WaitForExit()
Remove-Item $tempLua -Force -ErrorAction SilentlyContinue

if ($proc.ExitCode -ne 0) {
    Append-Log "FATAL: extract.php exited $($proc.ExitCode): $stderr"
    throw "extract.php failed (exit $($proc.ExitCode)): $stderr"
}

if ($stdout.Length -lt 100) {
    Append-Log "FATAL: extract.php returned suspiciously little ($($stdout.Length) bytes)"
    throw "extract.php returned empty output"
}

Write-Step '   ->' "Got $([math]::Round($stdout.Length/1KB)) KB of JSON"

# ── Hash + skip if unchanged ───────────────────────────────────────────────
$jsonBytes = [System.Text.Encoding]::UTF8.GetBytes($stdout)
$sha = [System.Security.Cryptography.SHA256]::Create()
$hash = ($sha.ComputeHash($jsonBytes) | ForEach-Object { $_.ToString('x2') }) -join ''

$lastHash = if (Test-Path $HashFile) { (Get-Content $HashFile -Raw).Trim() } else { '' }

Write-Step '[4/5]' "Payload SHA-256 = $hash"

if (-not $Force -and $hash -eq $lastHash) {
    Append-Log "no-op (hash unchanged: $hash)"
    Write-Step '   ->' "Unchanged since last sync. Exiting clean."
    exit 0
}

if ($DryRun) {
    Append-Log "dry-run (would have uploaded $($jsonBytes.Length) bytes, hash $hash)"
    Write-Step '   ->' "Dry run - skipping upload."
    exit 0
}

# ── Gzip + POST ────────────────────────────────────────────────────────────
Write-Step '[5/5]' "POST $IngestUrl"

$ms = New-Object System.IO.MemoryStream
$gz = New-Object System.IO.Compression.GZipStream($ms, [System.IO.Compression.CompressionLevel]::Optimal)
$gz.Write($jsonBytes, 0, $jsonBytes.Length)
$gz.Dispose()
$gzBytes = $ms.ToArray()
$ms.Dispose()

Write-Step '   ->' "Gzipped: $($jsonBytes.Length) -> $($gzBytes.Length) bytes ($([math]::Round($gzBytes.Length/$jsonBytes.Length*100,1))%)"

try {
    $resp = Invoke-WebRequest -Uri $IngestUrl `
        -Method POST `
        -Headers @{
            'Authorization' = "Bearer $IngestToken"
            'Content-Encoding' = 'gzip'
            'Content-Type' = 'application/json'
        } `
        -Body $gzBytes `
        -TimeoutSec 60 `
        -UseBasicParsing

    if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 300) {
        Set-Content -Path $HashFile -Value $hash -Encoding utf8
        Append-Log "OK ($($resp.StatusCode)): hash $hash, $($gzBytes.Length) bytes gzipped"
        Write-Step '   ->' "OK $($resp.StatusCode)"
        exit 0
    } else {
        Append-Log "FAIL ($($resp.StatusCode)): $($resp.Content)"
        throw "Ingest endpoint returned $($resp.StatusCode)"
    }
} catch {
    Append-Log "FAIL: $_"
    throw
}
