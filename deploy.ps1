# ─────────────────────────────────────────────────────────────────────────────
#  Regenesis — Local PowerShell deploy trigger
#
#  Builds frontend assets locally (Hostinger has no node), commits the
#  rebuilt public/build/ if it changed, pushes to origin, then SSHs to
#  the Hostinger box to run deploy.sh.
#
#  Mirrors C:\Dev\enhanceify-V2\deploy.ps1. Same SSH host (Hostinger
#  account u408983312); only AppDir differs since this is a separate
#  subdomain.
#
#  Usage:
#    pwsh ./deploy.ps1                           # build, commit-if-dirty, push, deploy
#    pwsh ./deploy.ps1 -SshHost 10.0.0.1         # override host
#    pwsh ./deploy.ps1 -SshUser other -SshPort 22
#    pwsh ./deploy.ps1 -SkipBuild                # skip npm run build
#    pwsh ./deploy.ps1 -SkipTest                 # skip local pest run
#    pwsh ./deploy.ps1 -DryRun                   # show what would run, do nothing
# ─────────────────────────────────────────────────────────────────────────────
[CmdletBinding()]
param(
    [string]$SshHost = '141.136.33.219',
    [string]$SshUser = 'u408983312',
    [int]   $SshPort = 65002,

    # Hostinger creates each subdomain as its own domain folder. Match
    # what hPanel set up when you added regenesis.enhanceify.co.uk.
    [string]$AppDir = '/home/u408983312/domains/regenesis.enhanceify.co.uk/laravel',

    [string]$Branch = 'main',

    [string]$BuildCommitMessage = "build: rebuild assets for deploy $(Get-Date -Format 'yyyy-MM-dd HH:mm')",

    [switch]$SkipBuild,
    [switch]$SkipTest,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

function Write-Step {
    param([string]$Label, [string]$Text)
    Write-Host ""
    Write-Host "$Label " -ForegroundColor Cyan -NoNewline
    Write-Host $Text
}

Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " regenesis.enhanceify.co.uk - Deploy pipeline" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Target : ${SshUser}@${SshHost}:${SshPort}"
Write-Host "  AppDir : $AppDir"
Write-Host "  Branch : $Branch"
Write-Host "  Build  : $(if ($SkipBuild) { 'SKIPPED' } else { 'yes' })"
Write-Host "  Tests  : $(if ($SkipTest)  { 'SKIPPED' } else { 'yes' })"
Write-Host "  DryRun : $(if ($DryRun)    { 'yes'     } else { 'no'  })"
Write-Host ""

$currentBranch = (git rev-parse --abbrev-ref HEAD).Trim()
if ($currentBranch -ne $Branch) {
    Write-Warning "On branch '$currentBranch' but deploying '$Branch'."
    if (-not $DryRun) {
        $answer = Read-Host "Continue? [y/N]"
        if ($answer -notmatch '^[yY]') { throw "Aborted." }
    }
}

# ── 1. Run the test suite locally ────────────────────────────────────
if (-not $SkipTest) {
    Write-Step "[1/6]" "Running local test suite..."
    if ($DryRun) {
        Write-Host "       (dry run - would run: php artisan test)"
    } else {
        php artisan test
        if ($LASTEXITCODE -ne 0) {
            throw "Tests failed. Fix before deploying (or -SkipTest if you know what you're doing)."
        }
    }
} else {
    Write-Step "[1/6]" "Skipping test suite (-SkipTest)"
}

# ── 2/3. Build frontend assets ───────────────────────────────────────
if (-not $SkipBuild) {
    Write-Step "[2/6]" "Installing/updating node modules..."
    if (-not (Test-Path 'node_modules')) {
        Write-Host "       node_modules missing, running npm ci..."
        if (-not $DryRun) { npm ci; if ($LASTEXITCODE -ne 0) { throw "npm ci failed." } }
    } else {
        $lockTime    = (Get-Item 'package-lock.json').LastWriteTime
        $modulesTime = (Get-Item 'node_modules').LastWriteTime
        if ($lockTime -gt $modulesTime) {
            Write-Host "       package-lock.json newer than node_modules, running npm ci..."
            if (-not $DryRun) { npm ci; if ($LASTEXITCODE -ne 0) { throw "npm ci failed." } }
        } else {
            Write-Host "       node_modules up to date."
        }
    }

    Write-Step "[3/6]" "Building assets with Vite..."
    if ($DryRun) {
        Write-Host "       (dry run - would run: npm run build)"
    } else {
        npm run build
        if ($LASTEXITCODE -ne 0) { throw "Vite build failed." }
    }
} else {
    Write-Step "[2/6]" "Skipping npm install (-SkipBuild)"
    Write-Step "[3/6]" "Skipping asset build (-SkipBuild)"
}

# ── 4. Commit any build changes ──────────────────────────────────────
Write-Step "[4/6]" "Checking for build changes..."
$buildStatus = git status --porcelain public/build 2>$null
if ($buildStatus) {
    Write-Host "       Rebuilt assets differ from last commit:"
    Write-Host $buildStatus
    if (-not $DryRun) {
        git add public/build
        git commit -m $BuildCommitMessage | Out-Null
        Write-Host "       OK Committed as: $BuildCommitMessage" -ForegroundColor Green
    } else {
        Write-Host "       (dry run - would commit public/build)"
    }
} else {
    Write-Host "       No build changes - nothing to commit."
}

$otherStatus = git status --porcelain 2>$null
if ($otherStatus) {
    Write-Warning "Uncommitted changes remain (outside public/build):"
    Write-Host $otherStatus
    if (-not $DryRun) {
        $answer = Read-Host "Continue deploying HEAD anyway (these changes will NOT ship)? [y/N]"
        if ($answer -notmatch '^[yY]') { throw "Aborted - commit your work and rerun." }
    }
}

# ── 5. Push to origin ────────────────────────────────────────────────
Write-Step "[5/6]" "Pushing to origin/$Branch..."
$unpushed = git log "origin/$Branch..HEAD" --oneline 2>$null
if ($unpushed) {
    Write-Host "       Unpushed commits:"
    Write-Host $unpushed
    if ($DryRun) {
        Write-Host "       (dry run - would run: git push origin $Branch)"
    } else {
        git push origin $Branch
        if ($LASTEXITCODE -ne 0) { throw "git push failed." }
    }
} else {
    Write-Host "       Already in sync with origin/$Branch."
}

# ── 6. Trigger the server-side deploy ────────────────────────────────
Write-Step "[6/6]" "Running deploy.sh on ${SshUser}@${SshHost}:${SshPort}..."
$remoteCmd = "APP_DIR='$AppDir' BRANCH='$Branch' bash '$AppDir/deploy.sh'"
$sshTarget = "${SshUser}@${SshHost}"

if ($DryRun) {
    Write-Host "       (dry run - would run: ssh -p $SshPort $sshTarget `"$remoteCmd`")"
    Write-Host ""
    Write-Host "OK Dry run complete. No changes made." -ForegroundColor Yellow
    exit 0
}

ssh -p $SshPort $sshTarget $remoteCmd
$exit = $LASTEXITCODE

Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
if ($exit -eq 0) {
    Write-Host " OK Deploy finished cleanly." -ForegroundColor Green
} else {
    Write-Host " FAIL Deploy exited with code $exit" -ForegroundColor Red
    Write-Host "==================================================" -ForegroundColor Cyan
    exit $exit
}
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""
