<#
    ElationPic - reset to a fresh (uninstalled) state

    Removes everything the setup wizard created, so you can run the
    installation flow again from scratch.

    Usage (from the project root):
        powershell -ExecutionPolicy Bypass -File tests\reset-install.ps1

    What it removes:
        data/config.php                 the configuration (incl. password hash)
        data/database.sqlite            the database
        data/database.sqlite-wal        SQLite WAL sidecar, if present
        data/database.sqlite-shm        SQLite shm sidecar, if present
        data/logs/app.log               runtime log
        data/logs/login_attempts.json   login rate-limit state

    What it KEEPS:
        directories (data, data/logs, data/sessions, data/tmp,
        public/uploads, public/uploads/thumbs) - they are recreated anyway
        uploaded image files - only listed, never deleted, unless you
        confirm explicitly (so you cannot lose pictures by accident)

    NOTE: pure ASCII on purpose. Windows PowerShell 5.1 misreads non-ASCII
    .ps1 files that lack a UTF-8 BOM.
#>

[CmdletBinding()]
param(
    [switch]$Force,
    [switch]$IncludeUploads
)

$ErrorActionPreference = "Continue"
$root = Split-Path -Parent $PSScriptRoot

function Say($m)  { Write-Host $m }
function Ok($m)   { Write-Host ("  [OK]   " + $m) -ForegroundColor Green }
function Skip($m) { Write-Host ("  [SKIP] " + $m) -ForegroundColor DarkGray }
function Warn3($m){ Write-Host ("  [WARN] " + $m) -ForegroundColor DarkYellow }

Write-Host ""
Write-Host "ElationPic - reset installation" -ForegroundColor White
Write-Host ("Project root: " + $root)


if (-not $Force) {
    Write-Host "This will delete the following, returning the site to an"
    Write-Host "uninstalled state (the setup wizard will appear again):"
    Write-Host ""
    Write-Host "    data\config.php" -ForegroundColor Yellow
    Write-Host "    data\database.sqlite (+ -wal / -shm if present)" -ForegroundColor Yellow
    Write-Host "    data\logs\app.log" -ForegroundColor Yellow
    Write-Host "    data\logs\login_attempts.json" -ForegroundColor Yellow
    if ($IncludeUploads) {
        Write-Host "    public\uploads\*  <-- INCLUDING UPLOADED IMAGES" -ForegroundColor Red
    }
    Write-Host ""
    $ans = Read-Host "Proceed? (y/N)"
    if ($ans -ne "y" -and $ans -ne "Y") {
        Write-Host "Aborted. Nothing was changed." -ForegroundColor DarkYellow
        exit 0
    }
}

$removed = 0

# --- configuration ---
$cfg = Join-Path $root "data\config.php"
if (Test-Path $cfg) { Remove-Item $cfg -Force; Ok "removed data\config.php"; $removed++ }
else { Skip "data\config.php (not present)" }

# --- database and its SQLite sidecars ---
foreach ($name in @("database.sqlite","database.sqlite-wal","database.sqlite-shm")) {
    $p = Join-Path $root ("data\" + $name)
    if (Test-Path $p) { Remove-Item $p -Force; Ok ("removed data\" + $name); $removed++ }
    else { Skip ("data\" + $name + " (not present)") }
}

# --- runtime state ---
foreach ($name in @("app.log","login_attempts.json")) {
    $p = Join-Path $root ("data\logs\" + $name)
    if (Test-Path $p) { Remove-Item $p -Force; Ok ("removed data\logs\" + $name); $removed++ }
    else { Skip ("data\logs\" + $name + " (not present)") }
}

# --- uploaded images (only on explicit request) ---
$upDir = Join-Path $root "public\uploads"
if (Test-Path $upDir) {
    $imgs = Get-ChildItem -Path $upDir -Recurse -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -ne ".gitkeep" }
    if ($imgs.Count -gt 0) {
        if ($IncludeUploads) {
            foreach ($f in $imgs) { Remove-Item $f.FullName -Force -ErrorAction SilentlyContinue }
            Ok ("removed " + $imgs.Count + " uploaded file(s)")
            $removed += $imgs.Count
        } else {
            Warn3 ($imgs.Count + " uploaded image(s) were left in place.")
            Write-Host "         To remove them too, re-run with -IncludeUploads" -ForegroundColor DarkYellow
        }
    } else { Skip "public\uploads (no images)" }
}

Write-Host ""
Write-Host ("Removed " + $removed + " item(s).") -ForegroundColor White
Write-Host ""

# --- verify the reset ---
Write-Host "Verification:" -ForegroundColor Cyan
$stillCfg = Test-Path (Join-Path $root "data\config.php")
$stillDb  = Test-Path (Join-Path $root "data\database.sqlite")
if (-not $stillCfg -and -not $stillDb) {
    Ok "configuration and database are gone - the site is uninstalled"
} else {
    Warn3 "some files remain; the wizard may not appear"
}

Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "  1. Open the site in a browser. It should redirect to the setup wizard."
Write-Host "  2. If you still see the old site, the browser or PHP may be caching;"
Write-Host "     do a hard refresh (Ctrl+F5)."
Write-Host "  3. If the wizard does NOT appear, check that data\config.php is gone."
