<#
    Elation Image - documentation integrity check

    Verifies that delivered documents are not structurally damaged, and that
    cross-references point at files that actually exist.

    Background: this project repeatedly suffered "heading glued onto a table
    row" and "whole section overwritten" damage during long editing sessions.
    Run this after any doc edit to catch it immediately.

    Usage (project root):
        powershell -ExecutionPolicy Bypass -File tests\check-docs.ps1

    NOTE: pure ASCII on purpose - Windows PowerShell 5.1 misreads non-ASCII
    .ps1 files that lack a UTF-8 BOM, which can break the parser.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = "Continue"
$script:bad = 0
$BT = [char]96
$FENCE = $BT + $BT + $BT

function Ok($m)   { Write-Host ("  [OK]   " + $m) -ForegroundColor Green }
function Bad($m)  { $script:bad++; Write-Host ("  [FAIL] " + $m) -ForegroundColor Red }
function Head($m) { Write-Host ""; Write-Host ("== " + $m) -ForegroundColor Cyan }

$root = Split-Path -Parent $PSScriptRoot

$docs = @(
    "README.md",
    "LICENSE",
    "docs\DESIGN.md",
    "docs\DEVELOPMENT.md",
    "docs\TEST-PLAN.md",
    "deploy\README-DEPLOY.md",
    "deploy\FIX-uploads-no-exec.conf"
)

Head "1. Documents exist"
foreach ($d in $docs) {
    if (Test-Path (Join-Path $root $d)) { Ok $d } else { Bad ("missing: " + $d) }
}

Head "2. Code fences are balanced"
foreach ($d in $docs) {
    $p = Join-Path $root $d
    if (-not (Test-Path $p)) { continue }
    $n = 0
    foreach ($line in (Get-Content $p -Encoding UTF8)) { if ($line -match ("^\s*" + $FENCE)) { $n++ } }
    if ($n % 2 -eq 0) { Ok ($d + " (" + $n + " fences, paired)") }
    else { Bad ($d + " has unbalanced code fences (" + $n + ")") }
}

Head "3. No headings glued onto table rows or prose"
foreach ($d in $docs) {
    $p = Join-Path $root $d
    if (-not (Test-Path $p)) { continue }
    $ln = 0; $hits = 0; $inFence = $false
    # -Encoding UTF8 is REQUIRED: PowerShell 5.1 otherwise decodes the file using the
    # system ANSI code page (GBK on zh-CN), which corrupts every non-ASCII character.
    foreach ($line in (Get-Content $p -Encoding UTF8)) {
        $ln++
        if ($line -match ("^\s*" + $FENCE)) { $inFence = -not $inFence; continue }
        if ($inFence) { continue }
        $idx = $line.IndexOf("## ")
        if ($idx -gt 2 -and -not $line.TrimStart().StartsWith("#")) {
            Bad ($d + " line " + $ln + " looks like a glued heading: " + $line.Substring(0, [Math]::Min(60, $line.Length)))
            $hits++
        }
    }
    if ($hits -eq 0) { Ok $d }
}

Head "4. No leftover references to removed documents"
# The repository ships only the documents needed to run and develop the
# project. Any stray reference to a document that is no longer present would
# send a reader to a dead end.
$removed = @("SECURITY-AUDIT", "DELIVERY-STATUS", "OPTIMIZATION-CHECKLIST")
$stale = @()
Get-ChildItem -Path $root -Recurse -File -Include *.md,*.ps1,*.php -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -notmatch "\\\.git\\\\" -and $_.Name -ne "check-docs.ps1" } |
    ForEach-Object {
        $rel = $_.FullName.Substring($root.Length + 1)
        foreach ($name in $removed) {
            if (Select-String -Path $_.FullName -Pattern $name -Quiet -ErrorAction SilentlyContinue) {
                $stale += ($rel + " -> " + $name)
            }
        }
    }
if ($stale.Count -eq 0) { Ok "no stale document references" }
else { Bad ("stale references: " + ($stale -join "; ")) }


Head "7. No regex escapes lost in scripts"
# Recurring defect: writing '\s+' through tooling can silently yield 's+', which is
# a VALID regex that simply never matches. The failure is invisible: the assertion
# just returns no match. This bit us five times (F-70, F-76, F-77, F-78, F-79), so
# it is checked mechanically rather than by discipline.
#
# A likely-lost-escape looks like a lone s/d/w/b immediately followed by + or *
# in a line that also mentions a regex operation.
$scriptDir = Join-Path $root "tests"
$suspicious = @()
Get-ChildItem -Path $scriptDir -Filter "*.ps1" | ForEach-Object {
    $file = $_.Name
    $n = 0
    foreach ($line in (Get-Content $_.FullName -Encoding UTF8)) {
        $n++
        if ($line -match "^\s*#") { continue }
        if ($line -notmatch "Match|match|replace|regex") { continue }
        # 's+', 'd+', 'w+' etc. NOT preceded by a backslash
        if ($line -match "(^|[^\\A-Za-z0-9_])(s|d|w|b)(\+|\*)") {
            $suspicious += ($file + ":" + $n)
        }
    }
}
if ($suspicious.Count -eq 0) { Ok "no lost regex escapes" }
else { Bad ("possible lost escapes: " + ($suspicious -join ", ")) }

Head "8. Packaging script is present and ASCII-safe"
# The packaging script writes its own quick-start file containing Chinese, so
# it CANNOT be pure ASCII like the other scripts. What it needs instead is a
# UTF-8 BOM: PowerShell 5.1 reads BOM-less .ps1 as ANSI and mangles non-ASCII,
# which turns into a syntax error. Verified empirically: same content with a
# BOM prints Chinese correctly, without a BOM it prints mojibake.
$pkg = Join-Path $root "tools\make-package.ps1"
if (-not (Test-Path $pkg)) {
    Bad "tools/make-package.ps1 is missing"
} else {
    Ok "packaging script exists"

    $bytes = [System.IO.File]::ReadAllBytes($pkg)
    $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
    $nonAscii = 0
    foreach ($b in $bytes) { if ($b -gt 127) { $nonAscii++ } }

    if ($nonAscii -eq 0) {
        Ok "packaging script is pure ASCII (no BOM needed)"
    } elseif ($hasBom) {
        Ok "packaging script has non-ASCII and carries a UTF-8 BOM"
    } else {
        Bad "packaging script has non-ASCII but NO BOM - PowerShell 5.1 will mangle it"
    }

    # The script must refuse to ship secrets. Check the guard is still there.
    $pkgText = Get-Content $pkg -Raw -Encoding UTF8
    $guardsSecret = ($pkgText -match "data/config\.php") -and ($pkgText -match "database\.sqlite")
    if ($guardsSecret) { Ok "packaging script excludes config and database" }
    else { Bad "packaging script no longer excludes config/database" }

    # And it must check uploads too - an early version leaked user images
    # because the whole public/ tree was copied.
    $guardsUploads = ($pkgText -match "isUserImage") -and ($pkgText -match "IncludeUploads")
    if ($guardsUploads) { Ok "packaging script guards user images" }
    else { Bad "packaging script no longer guards user images" }
}

Write-Host "================ summary ================" -ForegroundColor White
if ($script:bad -gt 0) { Write-Host ("PROBLEMS: " + $script:bad) -ForegroundColor Red }
else { Write-Host "PROBLEMS: 0" -ForegroundColor Green }
Write-Host "========================================="
if ($script:bad -gt 0) { exit 1 } else { exit 0 }