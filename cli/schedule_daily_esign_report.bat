@echo off
REM ============================================================
REM Daily PO E-Signature report — 23:00 IST
REM Windows Task Scheduler:
REM   Trigger: Daily at 23:00
REM   Action: Start this .bat
REM Recipients: Workflow → Daily Report Assignment
REM ============================================================
cd /d C:\MAMP\htdocs\sap_reports
if not exist "storage\logs" mkdir "storage\logs"

set LOG=C:\MAMP\htdocs\sap_reports\storage\logs\daily_esign_report.log
echo ===== %DATE% %TIME% =====>> "%LOG%"

REM Prefer HTTP (Apache PHP has SQL Server driver). Falls back to CLI php.
curl -sS -m 180 "http://localhost:8888/sap_reports/cron_daily_esign_report.php?key=evolv-daily-esign-2300" >> "%LOG%" 2>&1
if %ERRORLEVEL% EQU 0 goto done

set PHP_EXE=
if exist "C:\MAMP\bin\php\php8.3.1\php.exe" set PHP_EXE=C:\MAMP\bin\php\php8.3.1\php.exe
if exist "C:\MAMP\bin\php\php8.2.14\php.exe" set PHP_EXE=C:\MAMP\bin\php\php8.2.14\php.exe
if "%PHP_EXE%"=="" set PHP_EXE=php
"%PHP_EXE%" "C:\MAMP\htdocs\sap_reports\cli\send_daily_esign_report.php" >> "%LOG%" 2>&1

:done
echo.>> "%LOG%"
exit /b 0
