@echo off
chcp 65001 >nul
title Mythoscopedia Book Server

echo ==========================================
echo   মিথোস্কোপিডিয়া বই সার্ভার চালু হচ্ছে...
echo ==========================================
echo.

REM Check if PHP exists
set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" (
    echo [ERROR] PHP not found at %PHP_EXE%
    echo Please install XAMPP or update the PHP_EXE path in this script.
    pause
    exit /b 1
)

REM Check if project directory exists (use short path to avoid Unicode path issues)
for %%I in ("%~dp0.") do set "BOOKS_DIR=%%~sI"
if not exist "%BOOKS_DIR%\index.php" (
    echo [ERROR] Books directory not found: %BOOKS_DIR%
    pause
    exit /b 1
)

REM Kill any existing PHP server on port 8000
for /f "tokens=5" %%a in ('netstat -ano ^| findstr :8000') do (
    taskkill /f /pid %%a >nul 2>&1
)

echo [INFO] Starting PHP built-in server on http://127.0.0.1:8000
echo [INFO] Document root: %BOOKS_DIR%
echo.

cd /d "%BOOKS_DIR%"
start "Mythoscopedia PHP Server" "%PHP_EXE%" -n -d extension_dir="C:\xampp\php\ext" -d extension=mbstring -d extension=zip -d upload_max_filesize=200M -d post_max_size=250M -d memory_limit=1024M -d max_execution_time=300 -S 127.0.0.1:8000

set "BOOK_URL=http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3"

REM Wait for server to start (retry up to 10 seconds)
set /a TRIES=0
:waitloop
timeout /t 1 >nul
set /a TRIES+=1
curl --noproxy "*" -s -o nul -w "%%{http_code}" http://127.0.0.1:8000/ 2>nul | findstr "200 301 302" >nul
if %errorlevel% equ 0 goto serverready
if %TRIES% lss 10 goto waitloop
echo [WARNING] Server did not respond yet. Check the "Mythoscopedia PHP Server" window for errors.
echo.
goto openbrowser

:serverready
echo [SUCCESS] Server started successfully!
echo.

:openbrowser
echo [URL] %BOOK_URL%
echo.
if exist "C:\Program Files\Google\Chrome\Application\chrome.exe" (
    start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" "%BOOK_URL%"
) else if exist "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" (
    start "" "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" "%BOOK_URL%"
) else (
    start "" "%BOOK_URL%"
)
echo [INFO] Browser opened. Close the "Mythoscopedia PHP Server" window to stop the server.
echo.

pause