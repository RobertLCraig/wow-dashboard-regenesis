' Silent wrapper for grm-sync.ps1, mirroring the sync-dev.vbs pattern in
' C:\Dev\syncToOneDrive. wscript fires this with no console window so
' Task Scheduler runs are invisible to the logged-in user.
'
' Triggered by GrmSync-Task.xml; never run by hand (use grm-sync.ps1 -Verbose
' directly if you want output).

Set sh = CreateObject("WScript.Shell")
strScript = WScript.ScriptFullName
strDir = Left(strScript, InStrRev(strScript, "\") - 1)
strPs1 = strDir & "\grm-sync.ps1"
sh.Run "pwsh -NoProfile -ExecutionPolicy Bypass -File """ & strPs1 & """", 0, False
