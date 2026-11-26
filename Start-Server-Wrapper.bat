@echo off
chcp 65001 >nul

REM This wrapper handles Unicode path issues by using short paths
REM Run this instead of Start-Server.bat directly

set "SCRIPT_DIR=%~dp0"
set "WRAPPER_PATH=%~f0"

REM Get short path for the directory
for %%I in ("%SCRIPT_DIR%") do set "SHORT_DIR=%%~sI"

echo Using short path: %SHORT_DIR%

REM Call the actual server script from the short path
cmd /c "%SHORT_DIR%\Start-Server.bat"