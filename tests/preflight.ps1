<#
    Elation Image - environment preflight

    Run this BEFORE anything else. It separates "environment problems" from
    "code problems" so you do not have to guess which one you are looking at.

    Usage (from the project root):
        powershell -ExecutionPolicy Bypass -File tests\preflight.ps1

    Optional:
        -Php  "<full path to php.exe>"   (auto-detected if omitted)
        -Base "http://localhost"

    NOTE: this file is intentionally pure ASCII. Windows PowerShell 5.1 reads
    .ps1 files using the system ANSI codepage when no UTF-8 BOM is present,
    which corrupts non-ASCII text and can break the parser. Keeping it ASCII
    removes that entire failure mode.
#>

[CmdletBinding()]
param(
    [string]$Php  = "",
    [string]$Base = "http://localhost"
)

$ErrorActionPreference = "Continue"
$script:fail = 0
$script:warn = 0

function Ok($m)   { Write-Host ("  [OK]   " + $m) -ForegroundColor Green }
function Bad($m)  { $script:fail++; Write-Host ("  [FAIL] " + $m) -ForegroundColor Red }
function Warn2($m){ $script:warn++; Write-Host ("  [WARN] " + $m) -ForegroundColor DarkYellow }
function Info($m) { Write-Host ("         " + $m) -ForegroundColor DarkGray }
function Head($m) { Write-Host ""; Write-Host ("== " + $m) -ForegroundColor Cyan }

$root = Split-Path -Parent $PSScriptRoot
Write-Host ""
Write-Host "Elation Image - environment preflight" -ForegroundColor White
Write-Host ("Project root: " + $root)

Head "1. PHP interpreter"
# Declared up front so the failure message can always report what was searched,
# even when -Php was supplied but did not resolve.
$roots = @()
# Detect PHP instead of assuming a fixed path. Installations vary, so we:
#   1. honor -Php if given
#   2. try "php" on PATH
#   3. walk upward from the project root looking for a bundled PHP
# If all fail we print exactly what was tried, so you can pass -Php directly
# without guessing.
if ($Php -eq "") {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $Php = $cmd.Source }
}

if ($Php -eq "") {
    $roots = @()
    # Search from the project root upward: some setups bundle PHP alongside
    # the web root rather than on PATH.
    $p = $root
    for ($i = 0; $i -lt 4 -and $p; $i++) {
        $roots += $p
        $parent = Split-Path -Parent $p
        if ($parent -eq $p -or -not $parent) { break }
        $p = $parent
    }
    # Look for a PHP directory relative to whatever we found while walking up,
    # so this works on any drive or custom install location.
    $explicit = @()
    # NOTE: PowerShell variable names are CASE-INSENSITIVE, so a loop variable
    # named $base would overwrite the $Base URL parameter. Always use a distinct
    # name here.
    foreach ($rootDir in ($roots | Select-Object -Unique)) {
        $explicit += (Join-Path $rootDir "php")
        $explicit += (Join-Path $rootDir "Extensions\php")
    }

    $cands = @()
    foreach ($d in ($explicit | Select-Object -Unique)) {
        if (-not (Test-Path $d)) { continue }
        try {
            # Two levels: <php>\<version>\php.exe, plus a direct hit.
            $direct = Join-Path $d "php.exe"
            if (Test-Path $direct) { $cands += $direct }
            $found = Get-ChildItem -Path $d -Filter "php.exe" -Recurse -Depth 2 -File -ErrorAction SilentlyContinue
            if ($found) { $cands += ($found | Select-Object -ExpandProperty FullName) }
        } catch { }
    }
    # Prefer an 8.0.x build; otherwise take the first found.
    $pref = $cands | Where-Object { $_ -match "8\.0" } | Select-Object -First 1
    if ($pref) { $Php = $pref }
    elseif ($cands.Count -gt 0) { $Php = $cands[0] }
}

if ($Php -ne "" -and -not (Test-Path $Php)) {
    # -Php was supplied but points nowhere: say so, and do not fall through to
    # an auto-search that would silently ignore the user's explicit choice.
    Bad ("the -Php path does not exist: " + $Php)
    Write-Host ""
    Write-Host "Cannot continue." -ForegroundColor Red
    exit 1
}

if ($Php -eq "" -or -not (Test-Path $Php)) {
    Bad "php.exe not found automatically."
    Info "Searched: PATH, and php.exe under these roots:"
    foreach ($r in ($roots | Select-Object -Unique)) { Info ("  " + $r) }
    Info ""
    Info "Re-run with the explicit path, for example:"
    Info "  powershell -ExecutionPolicy Bypass -File tests\preflight.ps1 -Php D:\path\to\php.exe"
    Info ""
    Info "Tip: if PHP is not on PATH, point at it directly, e.g."
    Info "     -Php C:\php\php.exe   or   -Php /usr/bin/php"
    Write-Host ""
    Write-Host "Cannot continue without PHP." -ForegroundColor Red
    exit 1
}
Ok ("found: " + $Php)
$ver = & $Php -r "echo PHP_VERSION;"
if ($ver -match "^8\.0\.") { Ok ("PHP version " + $ver + " (OK)") }
else { Warn2 ("PHP version " + $ver + " - project targets 8.0.x") }

Head "2. Required extensions"
$mods = & $Php -m
foreach ($k in @("pdo_sqlite","fileinfo","json")) {
    if ($mods -match ("(?m)^" + [regex]::Escape($k) + "$")) { Ok ($k + " enabled") }
    else { Bad ($k + " MISSING - required") }
}
foreach ($k in @("gd","mbstring")) {
    if ($mods -match ("(?m)^" + [regex]::Escape($k) + "$")) { Ok ($k + " enabled (optional)") }
    else { Warn2 ($k + " missing - thumbnails/multibyte will degrade (not a security issue)") }
}

Head "3. PHP syntax check (all source files)"
$phpFiles = @()
foreach ($d in @("src","public","data","tests")) {
    $p = Join-Path $root $d
    if (Test-Path $p) { $phpFiles += Get-ChildItem -Path $p -Recurse -Filter *.php -File }
}
$syntaxBad = 0
foreach ($f in $phpFiles) {
    $out = & $Php -l $f.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { Bad ("syntax error: " + $f.FullName); Info ($out -join " "); $syntaxBad++ }
}
if ($syntaxBad -eq 0) { Ok ($phpFiles.Count.ToString() + " PHP files passed syntax check") }

Head "4. Directories and write permissions"
foreach ($rel in @("data","data\logs","data\sessions","data\tmp","public\uploads","public\uploads\thumbs")) {
    $d = Join-Path $root $rel
    if (-not (Test-Path $d)) {
        try { New-Item -ItemType Directory -Force -Path $d | Out-Null; Warn2 ("created missing dir: " + $rel) }
        catch { Bad ("dir missing and cannot create: " + $rel) }
    } else {
        $w = $false
        try {
            $t = Join-Path $d (".wtest_" + [guid]::NewGuid().ToString("N"))
            Set-Content -Path $t -Value "x" -ErrorAction Stop
            Remove-Item $t -Force
            $w = $true
        } catch { }
        if ($w) { Ok ($rel + " (writable)") } else { Bad ($rel + " NOT writable") }
    }
}

Head "5. Configuration file"
$cfg = Join-Path $root "data\config.php"
if (-not (Test-Path $cfg)) {
    Bad "data\config.php missing - run: php data\install.php"
} else {
    Ok "data\config.php exists"
    # Build a small temporary PHP probe file instead of a long "php -r" one-liner.
    # Reason: passing PHP source through PowerShell string interpolation requires
    # escaping $, ", and backticks correctly; operators like ?? are easy to mangle
    # and the resulting error ("unexpected token :") says nothing about the cause.
    # A real file has no quoting layer at all.
    $cfgSlash = $cfg.Replace([char]92, [char]47)
    $probeFile = Join-Path $env:TEMP ("elation_probe_" + [guid]::NewGuid().ToString("N") + ".php")
    $probeSrc = @'
<?php
$c = @include $argv[1];
if (!is_array($c)) { echo "BADARRAY"; exit; }
$h = isset($c["admin_password_hash"]) && is_string($c["admin_password_hash"]) ? $c["admin_password_hash"] : "";
if ($h === "") { echo "HASH_EMPTY"; exit; }
if ($h === "REPLACE_ME") { echo "HASH_PLACEHOLDER"; exit; }
if ($h[0] !== "$" || strlen($h) < 20) { echo "HASH_MALFORMED"; exit; }
$bp = isset($c["base_path"]) && is_string($c["base_path"]) ? $c["base_path"] : "";
$fh = !empty($c["force_https"]) ? "1" : "0";
echo "OK|" . $bp . "|" . $fh;
'@
    Set-Content -Path $probeFile -Value $probeSrc -Encoding ASCII
    $probeOut = (& $Php $probeFile $cfgSlash 2>&1) -join ""
    Remove-Item $probeFile -Force -ErrorAction SilentlyContinue

    if ($probeOut -eq "BADARRAY") { Bad "config.php did not return an array (file may be corrupt)" }
    elseif ($probeOut -eq "HASH_EMPTY") { Bad "admin_password_hash not set - rerun the setup wizard" }
    elseif ($probeOut -eq "HASH_PLACEHOLDER") { Bad "admin_password_hash is still the placeholder" }
    elseif ($probeOut -eq "HASH_MALFORMED") { Bad "admin_password_hash looks malformed" }
    elseif ($probeOut -like "OK|*") {
        Ok "admin_password_hash set and well-formed (value not shown)"
        $parts2 = $probeOut.Split("|")
        $bp = $parts2[1]
        $fh = $parts2[2]
        Info ("base_path   = [" + $bp + "]")
        Info ("force_https = " + $fh)
        if ($fh -eq "1" -and $Base -like "http://*") {
            Warn2 "force_https=true but you are testing over HTTP."
            Info "Session cookie will carry Secure and the browser will NOT store it."
            Info "Symptom: correct password but you stay on the login page."
            Info "Fix: set force_https => false in data/config.php for local HTTP tests."
        }
        if ($bp.Length -gt 0 -and $bp.Substring(0,1) -ne "/") {
            Warn2 ("base_path does not start with a slash (value: " + $bp + ")")
        }
    } else {
        Warn2 ("could not read config.php cleanly: " + $probeOut)
    }
}

Head "6. Database"
$db = Join-Path $root "data\database.sqlite"
if (-not (Test-Path $db)) {
    Warn2 "database.sqlite missing - it is created on first use or by install.php"
} else {
    Ok "database.sqlite exists"
    $dbSlash = $db.Replace([char]92, [char]47)
    $qFile = Join-Path $env:TEMP ("elation_dbprobe_" + [guid]::NewGuid().ToString("N") + ".php")
    $qSrc = @'
<?php
try {
    $p = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r = $p->query("SELECT COUNT(*) FROM images");
    echo "ROWS|" . $r->fetchColumn();
} catch (Throwable $e) {
    echo "ERR";
}
'@
    Set-Content -Path $qFile -Value $qSrc -Encoding ASCII
    $q = (& $Php $qFile $dbSlash 2>&1) -join ""
    Remove-Item $qFile -Force -ErrorAction SilentlyContinue
    if ($q -eq "ERR") { Bad "cannot query images table - db corrupt or not initialized" }
    elseif ($q -like "ROWS|*") { Ok ("images table readable, " + $q.Substring(5) + " rows") }
    else { Warn2 ("unexpected database probe output: " + $q) }
}

# Resolve a URL found in HTML against the site base.
# An href beginning with "/" is absolute from the HOST root, so combining it with
# a $Base that already carries a path (e.g. http://host/public) would duplicate
# that path. We therefore derive the origin (scheme://host[:port]) once.
#
#   href = "/public/assets/style.css", Base = "http://host/public"
#     -> origin + href = "http://host/public/assets/style.css"   (correct)
#     -> Base + href   = "http://host/public/public/assets/..."  (wrong)
$origin = $Base
if ($Base -match "^(https?://[^/]+)") { $origin = $Matches[1] }

function Resolve-SiteUrl($base, $origin, $href) {
    if ($href -match "^https?://") { return $href }
    if ($href.StartsWith("/")) { return $origin + $href }
    return $base + "/" + $href
}

Head ("7. Site reachability (" + $Base + ")")
$siteUp = $false
try {
    $r = Invoke-WebRequest -Uri ($Base + "/") -UseBasicParsing -TimeoutSec 10 -MaximumRedirection 0 -ErrorAction Stop
    Ok ("homepage returned " + [int]$r.StatusCode)
    $siteUp = $true
    # Allow an optional cache-busting query (e.g. style.css?v=123): asset URLs
    # carry a version stamp so browsers pick up changes immediately.
    # One capture group covering the whole href, query included -- so we request
    # exactly the URL the browser would.
    $rx = [regex]::new('<link[^>]+href="([^"]*style\.css(?:\?[^"]*)?)"')
    $m = $rx.Match([string]$r.Content)
    if ($m.Success) {
        # $m.Value keeps the full attribute; re-extract href including any query
        $href = $m.Groups[1].Value
        $u = Resolve-SiteUrl $Base $origin $href
        try {
            $null = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
            Ok ("CSS loads (base_path is correct): " + $href)
        } catch { Bad ("CSS failed to load: " + $u + " - base_path or docroot is wrong") }
    } else { Warn2 "homepage did not reference style.css - wrong base_path or broken page" }
} catch {
    $resp = $_.Exception.Response
    if ($resp -ne $null) {
        $code = 0; try { $code = [int]$resp.StatusCode } catch { }
        Warn2 ("homepage returned " + $code + " (expected 200)")
    } else { Warn2 ("cannot connect to " + $Base + " - is Nginx running?") }
}

if ($siteUp) {
    $sensitive = @(
        "data/database.sqlite",
        "data/database.sqlite-wal",
        "data/config.php",
        "data/logs/app.log",
        "deploy/php.ini",
        "deploy/nginx.conf",
        "docs/DESIGN.md",
        "tests/fixtures/evil.php.jpg",
        "README.md"
    )
    $exposed = @()
    $checked = 0
    foreach ($rel in $sensitive) {
        try {
            $s = Invoke-WebRequest -Uri ($Base + "/" + $rel) -UseBasicParsing -TimeoutSec 8 -ErrorAction Stop
            if ([int]$s.StatusCode -eq 200) { $exposed += $rel }
        } catch {
            if ($_.Exception.Response -ne $null) { $checked++ }
        }
    }
    if ($exposed.Count -gt 0) {
        Bad ("EXPOSED - downloadable anonymously: " + ($exposed -join ", "))
        Info "Your document root is probably set to the project root."
        Info "It must point to <project>/public instead."
        Info "See deploy/README-DEPLOY.md for the site-root requirement."
    } elseif ($checked -gt 0) {
        Ok ("sensitive files are blocked (" + $checked + " paths tested)")
    } else {
        Warn2 "could not verify sensitive file blocking"
    }
}

Write-Host ""

Head "7b. Disk space"
# A full disk breaks far more than uploads:
#   - SQLite cannot commit (writes fail)
#   - PHP cannot write session files -> the whole site can start erroring
#   - logs cannot be appended
# The symptom usually points somewhere else entirely, so it is worth
# catching BEFORE it happens. Thresholds are deliberately conservative.
try {
    $driveLetter = (Split-Path -Qualifier (Resolve-Path $root)).TrimEnd(":")
    $disk = Get-PSDrive -Name $driveLetter -ErrorAction Stop
    $freeBytes = [int64]$disk.Free
    $freeMb = [math]::Round($freeBytes / 1MB, 0)
    $freeGb = [math]::Round($freeBytes / 1GB, 2)
    # 500 MB is the "fix this now" line; 2 GB is the "plan a cleanup" line.
    if ($freeBytes -lt 500MB) {
        Bad ("only " + $freeMb + " MB free on " + $driveLetter + ": - writes will start failing")
        Info "Free space now: SQLite commits, PHP session files and logs all need room."
        Info "Delete old files in backup/ or data/ and remove unused uploads."
    } elseif ($freeBytes -lt 2GB) {
        Warn2 ("low disk space: " + $freeGb + " GB free on " + $driveLetter + ":")
        Info "Consider clearing backup/ or data/*.before-import-* files."
    } else {
        Ok ("disk space OK: " + $freeGb + " GB free on " + $driveLetter + ":")
    }

    # Report the directories that grow over time, so the number is actionable.
    $sizes = @(
        @{ Name = "public\uploads"; Path = (Join-Path $root "public\uploads") },
        @{ Name = "backup";         Path = (Join-Path $root "backup") },
        @{ Name = "data";           Path = (Join-Path $root "data") }
    )
    foreach ($s in $sizes) {
        if (-not (Test-Path $s.Path)) { continue }
        $sum = (Get-ChildItem -Path $s.Path -Recurse -File -ErrorAction SilentlyContinue |
                Measure-Object -Property Length -Sum).Sum
        if ($null -eq $sum) { $sum = 0 }
        Info ($s.Name + " = " + [math]::Round($sum / 1MB, 1) + " MB")
    }
} catch {
    Warn2 ("could not read disk space: " + $_.Exception.Message)
}

Head "8. Entry point diagnostics"
# Which URLs actually reach our PHP? This separates three failure modes that
# all look like "404" in a browser:
#   a) Nginx never routes to PHP (wrong site root / PHP location rule)
#   b) PHP runs but our files are not where the URL expects
#   c) The app redirects to the setup wizard - which is CORRECT behavior
$probes = @(
    @{ Path = "/";          Name = "homepage" },
    @{ Path = "/index.php"; Name = "index.php" },
    @{ Path = "/setup.php"; Name = "setup.php" },
    @{ Path = "/login.php"; Name = "login.php" }
)
foreach ($pr in $probes) {
    $u = $Base + $pr.Path
    $status = 0
    $body = ""
    $loc = ""
    try {
        $resp = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 10 -MaximumRedirection 0 -ErrorAction Stop
        $status = [int]$resp.StatusCode
        $body = [string]$resp.Content
        if ($resp.Headers["Location"]) { $loc = [string]$resp.Headers["Location"] }
    } catch {
        $hr = $_.Exception.Response
        if ($hr -ne $null) {
            try { $status = [int]$hr.StatusCode } catch { $status = 0 }
            if ($hr.Headers -and $hr.Headers["Location"]) { $loc = [string]$hr.Headers["Location"] }
            try {
                if ($hr.PSObject.Methods.Name -contains "GetResponseStream") {
                    $sr = New-Object System.IO.StreamReader($hr.GetResponseStream()); $body = $sr.ReadToEnd(); $sr.Close()
                } elseif ($hr.Content -ne $null) {
                    $body = $hr.Content.ReadAsStringAsync().GetAwaiter().GetResult()
                }
            } catch { }
        }
    }

    $isOurs  = ($body -match "Elation|csrf_token|elation_sid|setup_csrf|assets/style")
    $isNginx = ($body -match "nginx/")

    if ($status -eq 302 -and $loc -ne "") {
        Ok ($pr.Name + " -> 302 to " + $loc + "  (expected before initialization)")
    } elseif ($isOurs) {
        Ok ($pr.Name + " -> " + $status + " (served by the application)")
    } elseif ($isNginx) {
        Bad ($pr.Name + " -> " + $status + " from nginx (the application was NOT reached)")
        Info "The request never reached PHP. Check these two things:"
        Info "  1. Site document root must be <project>/public"
        Info "  2. The nginx PHP location must allow multi-segment paths:"
        Info "     location ~ ^(/[A-Za-z0-9_-]+)+[.]php$"
    } else {
        Warn2 ($pr.Name + " -> " + $status + " (response did not look like the app)")
    }
}


Head "9. Uploads directory must not execute PHP"
# This is the single most important check in this script: it is design spec
# priority #1 (prevent arbitrary code execution). A .php file under uploads/
# must never be interpreted by PHP-FPM.
#
# The probe writes a marker that appears ONLY in executed output, never in the
# source, so "executed" and "served as text" are distinguishable:
#     source:  echo "EXEC" . "UTED_42";
#     output:  EXECUTED_42
$upDirCheck = Join-Path $root "public\uploads"
if (-not (Test-Path $upDirCheck)) {
    Warn2 "public\uploads not found; skipping the execution check"
} else {
    $phpOpenTag = [char]60 + "?php"
    $phpCloseTag = "?" + [char]62
    $probeSource = $phpOpenTag + " echo " + [char]34 + "EXEC" + [char]34 + " . " + [char]34 + "UTED_42" + [char]34 + "; " + $phpCloseTag
    $probeName = "__preflight_exec_check.php"
    $probeFull = Join-Path $upDirCheck $probeName
    try {
        Set-Content -Path $probeFull -Value $probeSource -Encoding ASCII
        $pr = Invoke-WebRequest -Uri ($Base + "/uploads/" + $probeName) -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
        $pStatus = [int]$pr.StatusCode
        $pBody = [string]$pr.Content
        $pExec = ($pBody -match "EXECUTED_42")
        $pSrc = ($pBody -match [regex]::Escape($phpOpenTag))
        Remove-Item $probeFull -Force -ErrorAction SilentlyContinue
        if ($pExec) {
            Bad "CRITICAL: .php under /uploads/ IS EXECUTED (arbitrary code execution)"
            Info "Fix: apply deploy/FIX-uploads-no-exec.conf to your site config,"
            Info "     placing it BEFORE the generic location ~ [.]php$ rule."
            Info "See deploy/README-DEPLOY.md for the recommended gzip settings."
        } elseif ($pSrc) {
            Warn2 "uploads/ serves .php as static text (not executed, but not blocked)"
            Info "No code execution, but apply the same fix to return 403 instead."
        } elseif ($pStatus -eq 403 -or $pStatus -eq 404) {
            Ok "uploads/ blocks .php (status " + $pStatus + ")"
        } else {
            Warn2 ("uploads/ php probe returned " + $pStatus + " with an unrecognized body")
        }

    } catch {
        Remove-Item $probeFull -Force -ErrorAction SilentlyContinue
        $hr = $_.Exception.Response
        $code = 0
        if ($hr -ne $null) { try { $code = [int]$hr.StatusCode } catch { $code = 0 } }
        if ($code -eq 403 -or $code -eq 404) { Ok ("uploads/ blocks .php (status " + $code + ")") }
        else { Warn2 ("could not complete the uploads execution check (status " + $code + ")") }
    }

    # Counterpart check: blocking .php must NOT break legitimate image serving.
    # A rule set that 404s everything would pass the probe above while silently
    # breaking the whole site, so we verify a real image still loads.
    #
    # NOTE: this deliberately sits OUTSIDE the try/catch above. The probe request
    # throws on 403/404 (Invoke-WebRequest -ErrorAction Stop), so control jumps
    # straight to the catch; anything placed after the verdict inside the try
    # would be skipped on exactly the success path.
    try {
        # Do NOT name this $home: $HOME is a read-only automatic variable holding
        # the user profile path, and assigning to it throws
        # SessionStateUnauthorizedAccessException. PowerShell variable names are
        # case-insensitive, so $home/$Home/$HOME are all the same variable.
        $homeResp = Invoke-WebRequest -Uri ($Base + "/") -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
        $homeHtml = [string]$homeResp.Content
        $imgMatch = [regex]::Match($homeHtml, '/uploads/(thumbs/)?[a-f0-9]{32}[.](jpg|jpeg|png|gif|webp)')
        if ($imgMatch.Success) {
            $imgPath = $imgMatch.Value
            try {
                $ir = Invoke-WebRequest -Uri ($Base + $imgPath) -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
                Ok ("images still served: " + $imgPath + " (status " + [int]$ir.StatusCode + ")")
            } catch {
                $icode = 0
                $ihr = $_.Exception.Response
                if ($ihr -ne $null) { try { $icode = [int]$ihr.StatusCode } catch { $icode = 0 } }
                if ($icode -eq 200) {
                    Ok ("images still served: " + $imgPath)
                } else {
                    Bad ("images are BROKEN: " + $imgPath + " returned " + $icode)
                    Info "The uploads rule is too strict; it must still allow .jpg/.png/.gif/.webp."
                }
            }
        } else {
            Info "no image referenced on the homepage yet; upload one and re-run to verify"
        }
    } catch {
        Warn2 "could not verify that images still load"
    }
}

Write-Host "================ summary ================" -ForegroundColor White
if ($script:fail -gt 0) { Write-Host ("FAILED: " + $script:fail + "  <- fix these first") -ForegroundColor Red }
else { Write-Host "FAILED: 0" -ForegroundColor Green }
Write-Host ("WARNINGS: " + $script:warn)
Write-Host "========================================="
if ($script:fail -gt 0) { exit 1 } else { exit 0 }
