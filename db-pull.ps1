# ─────────────────────────────────────────────────────────────────────────────
#  Regenesis - Pull production database into local dev (MySQL)
#
#  Opens an SSH tunnel to the Hostinger box, runs mysqldump on prod,
#  and pipes the output directly into the local MySQL database.
#  No temp files, no format conversion.
#
#  Usage:
#    pwsh ./db-pull.ps1            # pull and import
#    pwsh ./db-pull.ps1 -DryRun   # show what would run, do nothing
# ─────────────────────────────────────────────────────────────────────────────
[CmdletBinding()]
param(
    [string]   $SshHost = '141.136.33.219',
    [string]   $SshUser = 'u408983312',
    [int]      $SshPort = 65002,
    [string]   $AppDir  = '/home/u408983312/domains/regenesis.enhanceify.co.uk/laravel',

    # Local MySQL connection (matches local .env)
    [string]   $LocalDb   = 'regenesis',
    [string]   $LocalUser = 'root',
    [string]   $LocalPass = 'root',

    # Tables excluded from the dump (ephemeral / session / queue state).
    [string[]] $ExcludeTables = @(
        'cache', 'cache_locks',
        'jobs', 'failed_jobs', 'job_batches',
        'sessions',
        'migrations',
        'password_reset_tokens'
    ),

    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

$MySqlBin    = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
$MysqlClient = Join-Path $MySqlBin 'mysql.exe'

function Write-Step {
    param([string]$Label, [string]$Text)
    Write-Host ""
    Write-Host "$Label " -ForegroundColor Cyan -NoNewline
    Write-Host $Text
}

Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " Regenesis - DB pull (prod MySQL -> local MySQL)" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Source : ${SshUser}@${SshHost}:${SshPort}"
Write-Host "  Target : ${LocalUser}@localhost/${LocalDb}"
Write-Host "  DryRun : $(if ($DryRun) { 'yes' } else { 'no' })"
Write-Host ""

$SshTarget = "${SshUser}@${SshHost}"

# ── 1. Read prod DB credentials ───────────────────────────────────────
Write-Step "[1/3]" "Reading prod DB credentials via SSH..."

if ($DryRun) {
    Write-Host "       (dry run - would read $AppDir/.env)"
    Write-Host "       (dry run - would migrate:fresh then pipe mysqldump -> local mysql)"
    Write-Host ""
    Write-Host "OK Dry run complete. No changes made." -ForegroundColor Yellow
    exit 0
}

$envLines = ssh -p $SshPort $SshTarget "grep -E '^DB_' '$AppDir/.env'"
if ($LASTEXITCODE -ne 0) {
    throw "SSH failed or could not read $AppDir/.env."
}

$creds = @{}
foreach ($line in $envLines) {
    if ($line -match '^(DB_\w+)=(.+)$') {
        $creds[$Matches[1]] = $Matches[2].Trim()
    }
}

$dbHost = if ($creds['DB_HOST']) { $creds['DB_HOST'] } else { '127.0.0.1' }
$dbPort = if ($creds['DB_PORT']) { $creds['DB_PORT'] } else { '3306' }
$dbName = $creds['DB_DATABASE']
$dbUser = $creds['DB_USERNAME']
$dbPass = $creds['DB_PASSWORD'] ?? ''

if (-not $dbName -or -not $dbUser) {
    throw "Could not parse DB_DATABASE / DB_USERNAME from prod .env."
}

Write-Host "       Database : $dbName on ${dbHost}:${dbPort}"

# ── 2. Wipe and recreate local schema ─────────────────────────────────
Write-Step "[2/3]" "Recreating local schema (migrate:fresh)..."

php artisan migrate:fresh --force
if ($LASTEXITCODE -ne 0) {
    throw "migrate:fresh failed."
}

# ── 3. Pipe prod dump straight into local MySQL ───────────────────────
Write-Step "[3/3]" "Streaming prod dump into local MySQL..."

$ignoreFlags = ($ExcludeTables | ForEach-Object { "--ignore-table=${dbName}.$_" }) -join ' '

# Single SSH command: mysqldump stdout is piped directly to local mysql stdin.
# --single-transaction : consistent snapshot, no table locks on InnoDB
# --no-create-info     : schema comes from migrate:fresh; skip CREATE TABLE
# --no-tablespaces     : avoids needing PROCESS privilege on some hosts
$dumpCmd = "mysqldump --host=$dbHost --port=$dbPort --user='$dbUser' --password='$dbPass' --single-transaction --no-create-info --no-tablespaces $ignoreFlags $dbName"

Write-Host "       Piping dump -> ${LocalUser}@localhost/${LocalDb}..."

# Suppress the mysql password warning by using MYSQL_PWD env var on the
# local side and a login-path approach on the server side isn't available,
# so we accept the mysqldump password-on-commandline on the server.
$env:MYSQL_PWD = $LocalPass
try {
    ssh -p $SshPort $SshTarget $dumpCmd | & $MysqlClient -u $LocalUser $LocalDb
    $exit = $LASTEXITCODE
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}

if ($exit -ne 0) {
    throw "mysql import failed (exit $exit)."
}

Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " OK Local DB is now a copy of production." -ForegroundColor Green
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""
