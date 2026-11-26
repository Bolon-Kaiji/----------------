$projectPath = "E:\মিথোস্কোপিডিয়া\আত্মতত্ত্ব ভেদ ১"
$booksDir = $projectPath
$phpExe = "C:\xampp\php\php.exe"

Write-Host "Project: $projectPath"
Write-Host "Books: $booksDir"
Write-Host "PHP: $phpExe"

if (-not (Test-Path $phpExe)) {
    Write-Error "PHP not found"
    exit 1
}

if (-not (Test-Path $booksDir)) {
    Write-Error "Books dir not found"
    exit 1
}

# Kill existing
$port = 8000
$proc = Get-NetTCPConnection -LocalPort $port -ErrorAction SilentlyContinue
if ($proc) {
    Stop-Process -Id $proc.OwningProcess -Force -ErrorAction SilentlyContinue
}

# Start server
cd $booksDir
$args = @(
    "-n",
    "-d", "extension_dir=C:\xampp\php\ext",
    "-d", "extension=mbstring",
    "-d", "extension=zip",
    "-d", "upload_max_filesize=200M",
    "-d", "post_max_size=250M",
    "-d", "memory_limit=1024M",
    "-d", "max_execution_time=300",
    "-S", "127.0.0.1:8000"
)

$process = Start-Process -FilePath $phpExe -ArgumentList $args -WindowStyle Hidden -PassThru
Write-Host "Server started with PID: $($process.Id)"
Write-Host "URL: http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3"

# Open browser
$chromePaths = @(
    "C:\Program Files\Google\Chrome\Application\chrome.exe",
    "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
)
foreach ($chrome in $chromePaths) {
    if (Test-Path $chrome) {
        Start-Process -FilePath $chrome -ArgumentList "http://127.0.0.1:8000/read.php?id=book_6b0656ef422e3"
        break
    }
}

Read-Host "Press Enter to stop server"
Stop-Process -Id $process.Id -Force
Write-Host "Server stopped"