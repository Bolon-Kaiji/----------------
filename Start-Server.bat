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

REM Check if project directory exists (now the root IS the books directory)
set "BOOKS_DIR=%~dp0"
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
start "Mythoscopedia PHP Server" "%PHP_EXE%" -n ^
    -d extension_dir="C:\xampp\php\ext" ^
    -d extension=mbstring ^
    -d extension=zip ^
    -d upload_max_filesize=200M ^
    -d post_max_size=250M ^
    -d memory_limit=1024M ^
    -d max_execution_time=300 ^
    -S 127.0.0.1:8000

REM Wait for server to start
timeout /t 3 >nul

REM Verify server is running
curl -s -o nul -w "%{http_code}" http://127.0.0.1:8000/ 2>nul | findstr "200 301 302" >nul
if %errorlevel% equ 0 (
    echo [SUCCESS] Server started successfully!
    echo [URL] http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3
    echo.

    REM Open in browser
    if exist "C:\Program Files\Google\Chrome\Application\chrome.exe" (
        start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" "http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3"
    ) else if exist "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" (
        start "" "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" "http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3"
    ) else (
        start "" "http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3"
    )

    echo [INFO] Browser opened. Press Ctrl+C to stop the server.
    echo.
) else (
    echo [WARNING] Server may not be ready yet. Check http://127.0.0.1:8000 manually.
    echo.
)

pause