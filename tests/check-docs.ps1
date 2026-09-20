<#
    ElationPic - documentation integrity check

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

    # 检查**所有** .ps1 的编码，而不只是打包脚本。
    #
    # 为什么这是机械检查而非靠自觉：某些编辑器/工具在保存时会静默丢掉 BOM。
    # 文件看起来完全正常，但 PowerShell 5.1 会按 ANSI 解读，
    # 中文变乱码、here-string 提前终止 —— 报出来的是"语法错误"，
    # 而真正的原因（编码）从报错信息里完全看不出来。
    # 这个坑实际踩过一次，所以在这里固定住。
    $psFiles = @(Get-ChildItem -Path $root -Recurse -File -Filter "*.ps1" |
                 Where-Object { $_.FullName -notlike "*\.git\*" })
    $bomProblems = @()
    foreach ($pf in $psFiles) {
        $fb = [System.IO.File]::ReadAllBytes($pf.FullName)
        $fHasBom = ($fb.Length -ge 3 -and $fb[0] -eq 0xEF -and $fb[1] -eq 0xBB -and $fb[2] -eq 0xBF)
        $fNonAscii = 0
        foreach ($by in $fb) { if ($by -gt 127) { $fNonAscii++ } }
        # 纯 ASCII 不需要 BOM；含非 ASCII 就必须有 BOM
        if ($fNonAscii -gt 0 -and -not $fHasBom) {
            $rel = $pf.FullName.Substring($root.Length + 1)
            $bomProblems += ($rel + " (non-ASCII without BOM)")
        }
    }
    if ($bomProblems.Count -eq 0) {
        Ok ("all " + $psFiles.Count + " .ps1 files have a safe encoding")
    } else {
        Bad ("BOM missing: " + ($bomProblems -join ", "))
    }

    # The script must refuse to ship secrets. Check the guard is still there.
    $pkgText = Get-Content $pkg -Raw -Encoding UTF8
    $guardsSecret = ($pkgText -match "data/config\.php") -and ($pkgText -match "database\.sqlite")
    if ($guardsSecret) { Ok "packaging script excludes config and database" }
    else { Bad "packaging script no longer excludes config/database" }

    # 必须同时守住用户图片：早期版本正是漏了这一点，
    # 结果把使用者的图一起打了进去（而当时的校验还报告"通过"）。
    $guardsUploads = ($pkgText -match "public/uploads/[*]") -and
                     ($pkgText -match "IncludeUploads") -and
                     ($pkgText -match "用户图片")
    if ($guardsUploads) { Ok "packaging script guards user images" }
    else { Bad "packaging script no longer guards user images" }

    # 目录条目必须被排除在"用户图片"判定之外。
    # CreateFromDirectory 会为空目录写独立条目；若不区分，
    # 正常的空目录骨架会被误报成泄露，导致打包在正确配置下也失败。
    $handlesDirEntries = ($pkgText -match '\$e\.Name -eq ""')
    if ($handlesDirEntries) { Ok "packaging script distinguishes directory entries" }
    else { Bad "packaging script would misreport empty directories as user images" }
}

Write-Host "================ summary ================" -ForegroundColor White
if ($script:bad -gt 0) { Write-Host ("PROBLEMS: " + $script:bad) -ForegroundColor Red }
else { Write-Host "PROBLEMS: 0" -ForegroundColor Green }
Write-Host "========================================="
if ($script:bad -gt 0) { exit 1 } else { exit 0 }
