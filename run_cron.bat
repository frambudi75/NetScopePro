@echo off
setlocal enabledelayedexpansion
title NetScopePro Background Task Runner

echo ===================================================
echo       NetScopePro Background Service Runner
echo ===================================================
echo.

:: Detect PHP Binary
set "PHP_BIN="
where php >nul 2>nul
if %errorlevel% equ 0 (
    set "PHP_BIN=php"
) else if exist "D:\xampp\php\php.exe" (
    set "PHP_BIN=D:\xampp\php\php.exe"
    set "MIBDIRS=D:\xampp\php\extras\mibs"
) else if exist "C:\xampp\php\php.exe" (
    set "PHP_BIN=C:\xampp\php\php.exe"
    set "MIBDIRS=C:\xampp\php\extras\mibs"
)

if "%PHP_BIN%"=="" (
    echo [ERROR] PHP executable not found!
    echo Please make sure PHP is installed in C:\xampp\php or D:\xampp\php,
    echo or added to your system PATH.
    pause
    exit /b 1
)

echo [OK] Using PHP: %PHP_BIN%
if defined MIBDIRS (
    echo [OK] Using MIB Directory: %MIBDIRS%
)
echo.

cd /d "%~dp0"

:menu
echo Select Execution Mode:
echo [1] Continuous Loop (Recommended for Background Service)
echo [2] Run Once Now (All Crons)
echo [3] Run Netwatch Only
echo [4] Run Scanner Only
echo [5] Run Switch Poller Only
echo [6] Run Database Cleanup Only
echo [0] Exit
echo.
set /p "choice=Enter choice [1-6, 0]: "

if "%choice%"=="1" goto loop_mode
if "%choice%"=="2" goto run_all_once
if "%choice%"=="3" goto run_netwatch_once
if "%choice%"=="4" goto run_scanner_once
if "%choice%"=="5" goto run_switch_once
if "%choice%"=="6" goto run_cleanup_once
if "%choice%"=="0" exit /b 0
goto menu

:run_all_once
echo.
echo [%date% %time%] Running all cron jobs...
"%PHP_BIN%" cron_netwatch.php
"%PHP_BIN%" cron_scanner.php
"%PHP_BIN%" cron_switch_poll.php
echo.
echo [DONE] All cron jobs executed.
pause
goto menu

:run_netwatch_once
echo.
"%PHP_BIN%" cron_netwatch.php
pause
goto menu

:run_scanner_once
echo.
"%PHP_BIN%" cron_scanner.php
pause
goto menu

:run_switch_once
echo.
"%PHP_BIN%" cron_switch_poll.php
pause
goto menu

:run_cleanup_once
echo.
"%PHP_BIN%" cron_cleanup.php
pause
goto menu

:loop_mode
echo.
echo ===================================================
echo   NetScopePro Background Service Running...
echo   Press Ctrl+C to stop.
echo ===================================================
echo.

set "CYCLE=0"

:loop_start
set /a CYCLE+=1
echo ---------------------------------------------------
echo [CYCLE #%CYCLE%] %date% %time%
echo ---------------------------------------------------

:: Netwatch runs every cycle (~60s)
echo [*] Checking Netwatch targets...
"%PHP_BIN%" cron_netwatch.php

:: Scanner runs every 5 cycles (~5 min)
set /a MOD5=CYCLE %% 5
if %MOD5% equ 0 (
    echo [*] Running Subnet Scanner...
    "%PHP_BIN%" cron_scanner.php
    echo [*] Polling Switch SNMP...
    "%PHP_BIN%" cron_switch_poll.php
)

echo [*] Sleeping 60 seconds until next cycle...
timeout /t 60 /nobreak >nul
goto loop_start
