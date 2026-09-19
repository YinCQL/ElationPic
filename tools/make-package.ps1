<#
    Elation Image - 打包脚本

    生成一个可分发的 zip 包，包含运行所需的全部代码与文档。

    用法：
        powershell -ExecutionPolicy Bypass -File tools\make-package.ps1
        powershell -ExecutionPolicy Bypass -File tools\make-package.ps1 -OutDir D:\dist
        powershell -ExecutionPolicy Bypass -File tools\make-package.ps1 -IncludeUploads

    参数：
        -OutDir <路径>      输出目录，默认 <项目>\dist
        -IncludeUploads     把 public\uploads 里的图片也打进去（默认不打）
        -IncludeTests       把 tests\fixtures 的大文件也打进去（默认不打）

    ------------------------------------------------------------------
    编码要求：本文件必须以 **UTF-8 带 BOM** 保存。
    Windows PowerShell 5.1 在没有 BOM 时会按 ANSI 解读 .ps1，
    里面的中文会变成乱码并导致语法错误。
    （实测确认：同一个文件带 BOM 正常输出中文，不带 BOM 输出乱码。）
    如果需要修改本文件，请确保编辑器保存时保留 BOM。
    ------------------------------------------------------------------

    打包原则：
        1. 绝不包含机密：data\config.php（含管理员密码哈希）、
           data\database.sqlite（真实数据）、data\logs（可能含访问痕迹）
        2. 绝不包含过程产物：data\*.before-import-*（回滚点）、data\tmp
        3. 默认不含用户图片：public\uploads 里是使用者的图，
           分发时应当为空。需要连图一起备份时用 -IncludeUploads
        4. 不含测试大文件：tests\fixtures 里的造数据文件
        5. 不含截图等审计过程产物：tests\shots
#>

[CmdletBinding()]
param(
    [string]$OutDir = "",
    [switch]$IncludeUploads,
    [switch]$IncludeTests
)

$ErrorActionPreference = "Stop"

# 项目根目录 = 本脚本所在目录的上一级
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
if ([string]::IsNullOrEmpty($OutDir)) {
    $OutDir = Join-Path $Root "dist"
}

Write-Host ""
Write-Host "=== Elation Image 打包 ===" -ForegroundColor Cyan
Write-Host "  项目根目录 : $Root"
Write-Host "  输出目录   : $OutDir"
Write-Host ""

if (-not (Test-Path $Root)) {
    Write-Host "[错误] 找不到项目根目录：$Root" -ForegroundColor Red
    exit 1
}

$stamp   = Get-Date -Format "yyyyMMdd-HHmmss"
$pkgName = "elation-image-$stamp"

if (-not (Test-Path $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

# 用系统临时目录搭建内容，最后整体压缩
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("elation-stage-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Path $stage -Force | Out-Null

Write-Host "  暂存目录   : $stage"
Write-Host ""

$script:copied  = 0
$script:skipped = New-Object System.Collections.ArrayList

function Copy-ItemSafe {
    param([string]$RelPath)
    $src = Join-Path $Root $RelPath
    if (-not (Test-Path $src)) { return }
    $dst = Join-Path $stage $RelPath
    $dstDir = Split-Path -Parent $dst
    if (-not (Test-Path $dstDir)) {
        New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
    }
    Copy-Item -Path $src -Destination $dst -Recurse -Force
    $script:copied++
}

function Skip-Note {
    param([string]$What, [string]$Why)
    [void]$script:skipped.Add(("  - " + $What.PadRight(36) + $Why))
}

# ---- 1. 应用代码 ----
# 注意：public 必须**逐项复制**，不能用 Copy-ItemSafe 整棵复制 ——
# 因为 public\uploads 里面是使用者上传的图片，默认不该进包。
# （早期版本整棵复制，导致默认打包会把用户图片一起发出去。）
Write-Host "  收集应用代码..." -ForegroundColor Gray
Copy-ItemSafe "src"
Copy-ItemSafe "deploy"

$pubSrc = Join-Path $Root "public"
if (Test-Path $pubSrc) {
    $pubDst = Join-Path $stage "public"
    New-Item -ItemType Directory -Path $pubDst -Force | Out-Null

    Get-ChildItem $pubSrc -File | ForEach-Object {
        Copy-Item $_.FullName $pubDst -Force
        $script:copied++
    }
    # 子目录：assets 要带，uploads 交给下面的开关决定
    $assetsSrc = Join-Path $pubSrc "assets"
    if (Test-Path $assetsSrc) {
        $assetsDst = Join-Path $pubDst "assets"
        New-Item -ItemType Directory -Path $assetsDst -Force | Out-Null
        Copy-Item (Join-Path $assetsSrc "*") $assetsDst -Recurse -Force
        $script:copied++
    }
}
foreach ($f in @("README.md", "index.php", ".htaccess", "nginx.htaccess", "public_root_index.php")) {
    Copy-ItemSafe $f
}

# 设计要求原文（存在就带上，便于对照）
if (Test-Path (Join-Path $Root "极简图床设计要求.md")) {
    Copy-Item (Join-Path $Root "极简图床设计要求.md") $stage -Force
    $script:copied++
}

# ---- 2. 文档 ----
Write-Host "  收集文档..." -ForegroundColor Gray
$docsSrc = Join-Path $Root "docs"
if (Test-Path $docsSrc) {
    $docsDst = Join-Path $stage "docs"
    New-Item -ItemType Directory -Path $docsDst -Force | Out-Null
    Get-ChildItem $docsSrc -File | ForEach-Object {
        Copy-Item $_.FullName $docsDst -Force
        $script:copied++
    }
}

# ---- 3. 测试脚本（不含大文件、截图、临时结果） ----
Write-Host "  收集测试脚本..." -ForegroundColor Gray
$testsSrc = Join-Path $Root "tests"
if (Test-Path $testsSrc) {
    Get-ChildItem $testsSrc -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($testsSrc.Length + 1)

        if (-not $IncludeTests -and $rel -like "fixtures*") { return }   # 造数据大文件
        if ($rel -like "shots*") { return }                              # 审计截图
        if ($rel -like "*.txt") { return }                               # 测试临时结果
        if ($rel -like "__*") { return }                                 # 临时探针

        $dst = Join-Path (Join-Path $stage "tests") $rel
        $dstDir = Split-Path -Parent $dst
        if (-not (Test-Path $dstDir)) {
            New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
        }
        Copy-Item $_.FullName $dst -Force
        $script:copied++
    }
}

# ---- 4. data：只带模板与脚本，绝不带真实数据 ----
Write-Host "  收集数据目录模板..." -ForegroundColor Gray
$dataDst = Join-Path $stage "data"
New-Item -ItemType Directory -Path $dataDst -Force | Out-Null

foreach ($f in @("config.sample.php", "install.php", "backup.php")) {
    $src = Join-Path $Root "data\$f"
    if (Test-Path $src) {
        Copy-Item $src $dataDst -Force
        $script:copied++
    }
}

# 空目录占位，保证解压后结构完整（程序本身也会自动创建）
foreach ($d in @("data\logs", "data\sessions", "data\tmp", "backup", "public")) {
    $p = Join-Path $stage $d
    if (-not (Test-Path $p)) {
        New-Item -ItemType Directory -Path $p -Force | Out-Null
    }
    Set-Content -Path (Join-Path $p ".gitkeep") -Value "" -NoNewline -Encoding ASCII
}

Skip-Note "data\config.php"              "含管理员密码哈希"
Skip-Note "data\database.sqlite"         "真实数据"
Skip-Note "data\logs\*"                 "运行日志"
Skip-Note "data\*.before-import-*"       "导入回滚点"
Skip-Note "data\tmp\*"                  "临时文件"

# ---- 5. uploads ----
# 无论是否包含图片，都要建出目录骨架，让解压后结构完整
foreach ($d in @("public\uploads", "public\uploads\thumbs")) {
    $p = Join-Path $stage $d
    if (-not (Test-Path $p)) {
        New-Item -ItemType Directory -Path $p -Force | Out-Null
    }
    Set-Content -Path (Join-Path $p ".gitkeep") -Value "" -NoNewline -Encoding ASCII
}

if ($IncludeUploads) {
    Write-Host "  收集图片（-IncludeUploads）..." -ForegroundColor Gray
    $upSrc = Join-Path $Root "public\uploads"
    if (Test-Path $upSrc) {
        Get-ChildItem $upSrc -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($upSrc.Length + 1)
            $dst = Join-Path (Join-Path $stage "public\uploads") $rel
            $dstDir = Split-Path -Parent $dst
            if (-not (Test-Path $dstDir)) {
                New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
            }
            Copy-Item $_.FullName $dst -Force
            $script:copied++
        }
    }
} else {
    Skip-Note "public\uploads\*"            "用户图片（需要时加 -IncludeUploads）"
}

# ------------------------------------------------------------------
# 生成包内说明
# ------------------------------------------------------------------
$quickStart = @'
Elation Image — 极简个人图床
============================

单管理员、零依赖的 PHP 图片托管程序。
完整文档见 README.md，部署细节见 deploy/README-DEPLOY.md。


最快上手（3 步）
----------------

1. 把本目录放到网站根目录之外，然后把网站根目录指向 public
   例如：
       程序位置   D:siteselation
       网站根目录 D:siteselationpublic

   Nginx 的 root 要写 public 的绝对路径，不要写项目根。

2. 浏览器打开站点，会进入安装向导

   向导会让你设置管理员密码、站点名称与描述、站点网址、
   每页显示数量、上传大小上限、缩略图最长边。

3. 完成后登录后台即可上传图片。


必须确认的三件事
----------------

1) uploads 目录不能执行 PHP

   最重要的一条安全设置。站点配置里必须有：

       location ^~ /uploads/ {
           # 不要在这里写 fastcgi_pass
       }

   并且要放在通用的 location ~ .php$ 之前。
   现成片段见 deployFIX-uploads-no-exec.conf。

   验证：powershell -ExecutionPolicy Bypass -File testscheck-uploads-exec.ps1

2) data 目录不能通过网址访问

   程序自带 .htaccess / nginx.htaccess 会拦截，
   建议在站点配置里再明确禁止一次。

3) data 目录需要可写

   PHP 进程要能写入 data（数据库、配置、日志、会话）。


目录说明
--------

    public          网站根目录（只有这里对公网可见）
      index.php      首页（公开浏览）
      setup.php      安装向导
      admin.php      后台管理
      uploads       图片存放处（不要执行 PHP）
      assets        CSS / JS

    src             程序代码（不在网站根目录内）
    data            数据库、配置、日志、会话（不可公开访问）
      config.sample.php   配置模板
      config.php          ← 安装后生成，含管理员密码，切勿外传
    docs            设计文档与审计记录
    deploy          部署配置片段与说明
    tests           自检与测试脚本
    backup          命令行备份的输出目录


日常维护
--------

备份（数据库 + 站点设置 + 原图，打包成一个 zip）：

    php dataackup.php

环境自检（PHP 版本 / 扩展 / 语法 / 权限 / 敏感文件 / 磁盘空间
         / uploads 是否可执行 PHP）：

    powershell -ExecutionPolicy Bypass -File testspreflight.ps1 -Base http://你的地址

功能与安全用例（需要管理员密码）：

    powershell -ExecutionPolicy Bypass -File testsun-tests.ps1 -Base http://你的地址 -Password 你的密码


环境要求
--------

    PHP    8.0.2 及以上
    扩展   pdo_sqlite、gd、fileinfo、json、zip
    Web    Nginx / Apache / 其他均可
    数据库 无需安装，使用 PHP 自带的 SQLite

实际扩展清单用预检脚本确认最准。


常见问题
--------

Q: 打开站点 404 或提示 "No input file specified"
A: 网站根目录没有指向 public。检查站点配置里的 root。

Q: 上传后图片打不开，或后台提示"文件已丢失"
A: uploads 目录不存在或不可写。程序会尝试自动创建，失败时需手工建目录。

Q: 忘记管理员密码
A: 删除 dataconfig.php 后重新打开站点，会回到安装向导。
   注意：这会重置所有站点设置，但不会影响已上传的图片和数据库。

Q: 磁盘快满了
A: 登录后台 → 备份页 → 维护区可看到原图占用与磁盘剩余。
   磁盘写满会导致上传失败，严重时 PHP 会话也写不进去，可能表现为全站异常。

Q: 想改每页显示数量 / 缩略图尺寸
A: 后台 → 设置。改缩略图尺寸后可在备份页的维护区一键重建已有图片的缩略图。
'@

Set-Content -Path (Join-Path $stage "快速上手.txt") -Value $quickStart -Encoding UTF8

# ------------------------------------------------------------------
# 压缩
# ------------------------------------------------------------------
$zipPath = Join-Path $OutDir ($pkgName + ".zip")
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Write-Host ""
Write-Host "  正在压缩..." -ForegroundColor Gray

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $stage,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)

Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue

# ------------------------------------------------------------------
# 汇总
# ------------------------------------------------------------------
$zipSize = (Get-Item $zipPath).Length

Add-Type -AssemblyName System.IO.Compression.FileSystem
$z = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$fileCount = $z.Entries.Count
$z.Dispose()

Write-Host ""
Write-Host "=== 打包完成 ===" -ForegroundColor Green
Write-Host ("  文件   : " + $zipPath)
Write-Host ("  大小   : " + [math]::Round($zipSize / 1KB, 1) + " KB")
Write-Host ("  条目数 : " + $fileCount)

Write-Host ""
Write-Host "  已排除的内容：" -ForegroundColor Yellow
foreach ($s in $skipped) { Write-Host $s }

if (-not $IncludeUploads) {
    Write-Host ""
    Write-Host "  提示：用户图片未包含。若要把图一起打包（例如整站迁移），" -ForegroundColor Yellow
    Write-Host "        加 -IncludeUploads 参数。" -ForegroundColor Yellow
}

# ------------------------------------------------------------------
# 交付前校验：包内不得出现机密或过程产物
# ------------------------------------------------------------------
Write-Host ""
Write-Host "  校验包内不含机密..." -ForegroundColor Gray

$bad = New-Object System.Collections.ArrayList
$z = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
foreach ($e in $z.Entries) {
    $n = $e.FullName

    $isSecret =
        ($n -eq "data/config.php") -or
        ($n -eq "data/database.sqlite") -or
        ($n -like "data/logs/*") -or
        ($n -like "data/tmp/*") -or
        ($n -like "*before-import*") -or
        ($n -like "*.before-import.*")

    # 用户图片：只有明确要求时才允许出现在包里。
    # 这一条是补上的 —— 早期版本漏检，导致默认打包把使用者的图一起发出去。
    $isUserImage = $false
    if ($n -like "public/uploads/*" -and $n -notlike "*.gitkeep") {
        $isUserImage = $true
    }

    # 空目录占位（.gitkeep）不算内容
    if ($isSecret -and $e.Length -gt 0 -and $n -notlike "*.gitkeep") {
        [void]$bad.Add($n)
    } elseif ($isUserImage -and -not $IncludeUploads) {
        [void]$bad.Add($n + "  <- 用户图片，未指定 -IncludeUploads")
    }
}
$z.Dispose()

if ($bad.Count -gt 0) {
    Write-Host "  [失败] 包内发现了不该有的文件：" -ForegroundColor Red
    foreach ($b in $bad) { Write-Host ("    " + $b) -ForegroundColor Red }
    Write-Host "  已删除该包，避免误分发。" -ForegroundColor Red
    Remove-Item $zipPath -Force
    exit 1
}
Write-Host "  [通过] 未发现配置、数据库、日志或回滚点" -ForegroundColor Green
Write-Host ""
