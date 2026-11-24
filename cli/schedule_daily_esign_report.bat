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

REM 1) Prefer HTTP (Apache PHP has SQL Server / PDO ODBC driver).
curl -sS -m 180 "https://apps.evolvclothing.com/sap_reports/cron_daily_esign_report.php?key=evolv-daily-esign-2300" >> "%LOG%" 2>&1
if %ERRORLEVEL% EQU 0 goto done

REM 2) CLI fallback — MUST use Apache php.ini so pdo_odbc loads
set PHP_EXE=C:\MAMP\bin\php\php8.3.1\php.exe
set PHP_INI=C:\MAMP\conf\php8.3.1\php.ini
if not exist "%PHP_EXE%" set PHP_EXE=C:\MAMP\bin\php\php8.2.14\php.exe
if not exist "%PHP_INI%" set PHP_INI=C:\MAMP\bin\php\php8.3.1\php.ini

echo CLI fallback with -c "%PHP_INI%">> "%LOG%"
"%PHP_EXE%" -c "%PHP_INI%" "C:\MAMP\htdocs\sap_reports\cli\send_daily_esign_report.php" >> "%LOG%" 2>&1

:done
echo.>> "%LOG%"
exit /b 0
