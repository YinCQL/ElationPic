<#
    Elation Image - uploads/ PHP execution check (definitive)

    Answers ONE question: if a .php file ends up under public/uploads/,
    does the web server EXECUTE it, serve it as text, or block it?

    This is the #1 security question for an image host.

    Usage:
        powershell -ExecutionPolicy Bypass -File tests\check-uploads-exec.ps1 -Base http://127.0.0.1:8099

    Pure ASCII on purpose (Windows PowerShell 5.1 misreads non-ASCII .ps1).
#>

[CmdletBinding()]
param(
    [string]$Base = "http://127.0.0.1:8099"
)

$ErrorActionPreference = "Continue"
$root = Split-Path -Parent $PSScriptRoot
$upDir = Join-Path $root "public\uploads"

Write-Host ""
Write-Host "Checking PHP execution under /uploads/" -ForegroundColor White
Write-Host ("Base: " + $Base)
Write-Host ""

if (-not (Test-Path $upDir)) {
    Write-Host ("uploads directory not found: " + $upDir) -ForegroundColor Red
    exit 2
}

# The marker appears ONLY in executed output, never in the source.
#   source:  echo "EXEC" . "UTED_42";
#   output:  EXECUTED_42
$marker = "EXECUTED_42"
$phpOpen = [char]60 + "?php"
$phpClose = "?" + [char]62
# Build: <?php echo "EXEC" . "UTED_42"; ?>
$probeSrc = $phpOpen + " echo " + [char]34 + "EXEC" + [char]34 + " . " + [char]34 + "UTED_42" + [char]34 + "; " + $phpClose

$probePath = Join-Path $upDir "__exec_check.php"
try {
    Set-Content -Path $probePath -Value $probeSrc -Encoding ASCII
} catch {
    Write-Host ("Cannot write probe file: " + $_.Exception.Message) -ForegroundColor Red
    exit 2
}

$url = $Base + "/uploads/__exec_check.php"
$status = 0
$body = ""
try {
    $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
    $status = [int]$r.StatusCode
    $body = [string]$r.Content
} catch {
    $resp = $_.Exception.Response
    if ($resp -ne $null) {
        try { $status = [int]$resp.StatusCode } catch { $status = 0 }
        try {
            if ($resp.PSObject.Methods.Name -contains "GetResponseStream") {
                $sr = New-Object System.IO.StreamReader($resp.GetResponseStream()); $body = $sr.ReadToEnd(); $sr.Close()
            } elseif ($resp.Content -ne $null) {
                $body = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()
            }
        } catch { }
    }
}

Remove-Item $probePath -Force -ErrorAction SilentlyContinue

$executed = ($body -match [regex]::Escape($marker))
$sourceServed = ($body -match [regex]::Escape($phpOpen))

Write-Host ("Requested : " + $url)
Write-Host ("Status    : " + $status)
$preview = ""
if ($body.Length -gt 0) { $preview = $body.Substring(0, [Math]::Min(120, $body.Length)) }
Write-Host ("Body start: " + $preview)
Write-Host ""

if ($executed) {
    Write-Host "RESULT: *** CRITICAL - THE PHP WAS EXECUTED ***" -ForegroundColor Red
    Write-Host "  A .php file under uploads/ is interpreted by PHP-FPM."
    Write-Host "  Anyone able to place a file there gets code execution."
    Write-Host "  FIX: apply the uploads/ block from deploy/nginx.conf so that"
    Write-Host "       /uploads/ is never routed to fastcgi_pass."
    exit 1
} elseif ($sourceServed) {
    Write-Host "RESULT: SAFE but improvable - served as STATIC TEXT, not executed." -ForegroundColor Yellow
    Write-Host "  The code did not run, so there is no code execution."
    Write-Host "  But the file was downloadable in source form, meaning nginx does"
    Write-Host "  not block .php under /uploads/."
    Write-Host "  RECOMMENDED: apply the uploads/ block from deploy/nginx.conf so such"
    Write-Host "  requests return 403 instead of exposing file contents."
    exit 0
} elseif ($status -eq 403 -or $status -eq 404) {
    Write-Host "RESULT: SAFEST - the request was blocked." -ForegroundColor Green
    Write-Host "  nginx refuses to serve or execute .php under /uploads/."
    exit 0
} else {
    Write-Host "RESULT: INCONCLUSIVE" -ForegroundColor Yellow
    Write-Host ("  Status " + $status + " with a body matching no known pattern.")
    exit 3
}