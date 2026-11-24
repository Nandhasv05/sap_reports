# Register Windows Task Scheduler job: Daily PO E-Signature report at 23:00 IST
# Run once (as Administrator):
#   powershell -ExecutionPolicy Bypass -File cli\register_daily_esign_task.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$bat = Join-Path $root 'cli\schedule_daily_esign_report.bat'
if (-not (Test-Path $bat)) { throw "Missing $bat" }

# Prefer the .bat (HTTP cron first, then CLI with Apache php.ini)
$taskName = 'Evolv_Daily_PO_Esign_Report'
$action = New-ScheduledTaskAction -Execute $bat -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Daily -At '23:00'
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Highest

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "Registered task '$taskName' — daily 23:00"
Write-Host "Action: $bat"
Write-Host "Dry-run (CLI with Apache php.ini):"
Write-Host '  C:\MAMP\bin\php\php8.3.1\php.exe -c C:\MAMP\conf\php8.3.1\php.ini cli\send_daily_esign_report.php --dry-run'
