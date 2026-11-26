<#
.SYNOPSIS
    Sets up Mythoscopedia Book Server to auto-start on Windows boot

.DESCRIPTION
    Creates a scheduled task that runs the PHP server at system startup
    with highest privileges, ensuring the book server is always available.
#>

param(
    [string]$ProjectPath = "E:\মিথোস্কোপিডিয়া\আত্মতত্ত্ব ভেদ ১",
    [string]$PhpExe = "C:\xampp\php\php.exe",
    [int]$Port = 8000,
    [string]$BooksDir = ""
)

# Get short path to handle Unicode characters
$shortPath = (Get-Item $ProjectPath).ShortName
$fullShortPath = Join-Path (Split-Path $ProjectPath -Parent) $shortPath

Write-Host "Project path: $ProjectPath" -ForegroundColor Cyan
Write-Host "Short path: $fullShortPath" -ForegroundColor Cyan

# Verify PHP exists
if (-not (Test-Path $PhpExe)) {
    Write-Error "PHP not found at: $PhpExe"
    exit 1
}

# Verify books directory exists (project root IS the books directory now)
$booksFullPath = $fullShortPath
if (-not (Test-Path $booksFullPath)) {
    Write-Error "Books directory not found at: $booksFullPath"
    exit 1
}

Write-Host "Books directory: $booksFullPath" -ForegroundColor Green

# Create the startup script
$startupScript = @"
@echo off
chcp 65001 >nul
title Mythoscopedia Book Server (Auto-Start)

REM Kill any existing server on port $Port
for /f "tokens=5" %%%%a in ('netstat -ano ^| findstr :$Port') do (
    taskkill /f /pid %%%%a >nul 2>&1
)

cd /d "$booksFullPath"
start "Mythoscopedia PHP Server" "$PhpExe" -n `
    -d extension_dir="C:\xampp\php\ext" `
    -d extension=mbstring `
    -d extension=zip `
    -d upload_max_filesize=200M `
    -d post_max_size=250M `
    -d memory_limit=1024M `
    -d max_execution_time=300 `
    -S 127.0.0.1:$Port

exit /b 0
"@

$startupScriptPath = Join-Path $fullShortPath "AutoStart-Server.bat"
$startupScript | Set-Content -Path $startupScriptPath -Encoding UTF8
Write-Host "Created startup script: $startupScriptPath" -ForegroundColor Green

# Create scheduled task for auto-start on boot
$taskName = "Mythoscopedia Book Server"
$action = New-ScheduledTaskAction -Execute "$startupScriptPath" -WorkingDirectory "$booksFullPath"
$trigger = New-ScheduledTaskTrigger -AtStartup -Delay 00:00:30
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RunOnlyIfNetworkAvailable
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

try {
    # Remove existing task if it exists
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        Write-Host "Removed existing task" -ForegroundColor Yellow
    }

    # Register new task
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "Auto-starts Mythoscopedia PHP Book Server on system boot"
    Write-Host "SUCCESS: Scheduled task '$taskName' created!" -ForegroundColor Green
    Write-Host "The server will auto-start on next boot." -ForegroundColor Cyan

    # Also create a manual start shortcut
    $shortcutPath = Join-Path $fullShortPath "Start-Server-Manual.bat"
    $manualScript = @"
@echo off
chcp 65001 >nul
title Mythoscopedia Book Server (Manual Start)

echo ==========================================
echo   মিথোস্কোপিডিয়া বই সার্ভার (ম্যানুয়াল)
echo ==========================================
echo.

REM Kill any existing server on port $Port
for /f "tokens=5" %%%%a in ('netstat -ano ^| findstr :$Port') do (
    taskkill /f /pid %%%%a >nul 2>&1
)

cd /d "$booksFullPath"
echo [INFO] Starting PHP server on http://127.0.0.1:$Port
echo [INFO] Document root: $booksFullPath
echo.

start "Mythoscopedia PHP Server" "$PhpExe" -n `
    -d extension_dir="C:\xampp\php\ext" `
    -d extension=mbstring `
    -d extension=zip `
    -d upload_max_filesize=200M `
    -d post_max_size=250M `
    -d memory_limit=1024M `
    -d max_execution_time=300 `
    -S 127.0.0.1:$Port

timeout /t 3 >nul

echo [SUCCESS] Server started!
echo [URL] http://127.0.0.1:$Port/read.php?id=book_6b0656ef422e3
echo.

REM Open in browser
if exist "C:\Program Files\Google\Chrome\Application\chrome.exe" (
    start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" "http://127.0.0.1:$Port/read.php?id=book_6b0656ef422e3"
) else if exist "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" (
    start "" "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" "http://127.0.0.1:$Port/read.php?id=book_6b0656ef422e3"
) else (
    start "" "http://127.0.0.1:$Port/read.php?id=book_6b0656ef422e3"
)

pause
"@
    $manualScript | Set-Content -Path $shortcutPath -Encoding UTF8
    Write-Host "Created manual start script: $shortcutPath" -ForegroundColor Green

} catch {
    Write-Error "Failed to create scheduled task: $_"
    exit 1
}

Write-Host "`n=== SUMMARY ===" -ForegroundColor Cyan
Write-Host "1. Auto-start on boot: ENABLED (Task: '$taskName')" -ForegroundColor Green
Write-Host "2. Manual start: Double-click 'Start-Server-Manual.bat'" -ForegroundColor Green
Write-Host "3. Server URL: http://127.0.0.1:$Port/read.php?id=book_6b0656ef422e3" -ForegroundColor Cyan
Write-Host "`nTo test now, run: & '$startupScriptPath'" -ForegroundColor Yellow