@echo off
setlocal enabledelayedexpansion

rem ─────────────────────────────────────────────────────────────────────
rem  Regenesis grm-sync installer.
rem
rem  Prompts for the dashboard ingest URL + bearer token, writes them to
rem  %LOCALAPPDATA%\regenesis-grm\config.json, and registers the
rem  scheduled task that fires grm-sync.vbs every 30 minutes.
rem
rem  Re-run any time to refresh credentials or re-import the task XML.
rem ─────────────────────────────────────────────────────────────────────

echo.
echo === Regenesis grm-sync installer ===
echo.

set "CONFIG_DIR=%LOCALAPPDATA%\regenesis-grm"
set "CONFIG_FILE=%CONFIG_DIR%\config.json"
set "TASK_NAME=RegenesisGrmSync"
set "REPO_ROOT=%~dp0..\.."
for %%I in ("%REPO_ROOT%") do set "REPO_ROOT=%%~fI"
set "TASK_XML=%REPO_ROOT%\tools\grm-sync\GrmSync-Task.xml"

if not exist "%CONFIG_DIR%" mkdir "%CONFIG_DIR%"

set "DEFAULT_URL=https://regenesis.enhanceify.co.uk/api/ingest/grm"
set /p INGEST_URL=Ingest URL [%DEFAULT_URL%]:
if "!INGEST_URL!"=="" set "INGEST_URL=%DEFAULT_URL%"

set /p INGEST_TOKEN=Bearer token (from Laravel .env GRM_INGEST_TOKEN):
if "!INGEST_TOKEN!"=="" (
    echo ERROR: Token is required.
    exit /b 1
)

> "%CONFIG_FILE%" echo {
>>"%CONFIG_FILE%" echo   "ingest_url": "%INGEST_URL%",
>>"%CONFIG_FILE%" echo   "ingest_token": "%INGEST_TOKEN%"
>>"%CONFIG_FILE%" echo }

echo.
echo Wrote %CONFIG_FILE%
echo.

echo Registering scheduled task "%TASK_NAME%"...
schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>&1
schtasks /Create /XML "%TASK_XML%" /TN "%TASK_NAME%"
if errorlevel 1 (
    echo ERROR: schtasks /Create failed.
    exit /b 1
)

echo.
echo Done. The task will run every 30 minutes (and at logon).
echo To trigger a one-off sync now:  schtasks /Run /TN "%TASK_NAME%"
echo To watch the log:               type "%CONFIG_DIR%\grm-sync.log"
echo.
