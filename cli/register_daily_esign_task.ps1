# Register Windows Task Scheduler job: Daily PO E-Signature report at 23:00 IST
# Run once (as Administrator):
#   powershell -ExecutionPolicy Bypass -File cli\register_daily_esign_task.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$phpCandidates = @(
    'C:\MAMP\bin\php\php8.3.1\php.exe',
    'C:\MAMP\bin\php\php8.2.0\php.exe',
    'C:\MAMP\bin\php\php.exe'
)
$php = $phpCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $php) { throw 'PHP executable not found under C:\MAMP\bin\php' }

$script = Join-Path $root 'cli\send_daily_esign_report.php'
if (-not (Test-Path $script)) { throw "Missing $script" }

$taskName = 'Evolv_Daily_PO_Esign_Report'
$action = New-ScheduledTaskAction -Execute $php -Argument "`"$script`"" -WorkingDirectory $root
# 23:00 local time (set PC timezone to India Standard Time for IST)
$trigger = New-ScheduledTaskTrigger -Daily -At '23:00'
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Highest

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "Registered task '$taskName' — daily 23:00"
Write-Host "PHP: $php"
Write-Host "Script: $script"
Write-Host "Dry-run test: `"$php`" `"$script`" --dry-run"
