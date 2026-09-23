<#
    ElationPic - 打包脚本

    生成可分发的 zip：解压后把网站根目录指向 public/ 即可安装。

    用法：
        powershell -ExecutionPolicy Bypass -File tools\make-package.ps1
        powershell -ExecutionPolicy Bypass -File tools\make-package.ps1 -OutDir D:\dist
        powershell -ExecutionPolicy Bypass -File tools\make-package.ps1 -IncludeUploads

    参数：
        -OutDir <路径>    输出目录，默认 <项目>\dist
        -IncludeUploads   连 public\uploads 里的图片一起打包（整站迁移用）
        -IncludeTests     连 tests\fixtures 的造数据大文件一起打包

    ------------------------------------------------------------------
    编码要求：本文件必须以 **UTF-8 带 BOM** 保存。
    Windows PowerShell 5.1 在没有 BOM 时按 ANSI 解读 .ps1，
    中文会变乱码并导致语法错误。
    ------------------------------------------------------------------

    打包原则：
      1. 绝不包含机密：data\config.php（含管理员密码哈希）、
         data\database.sqlite（真实数据）、data\logs（可能含访问痕迹）
      2. 绝不包含过程产物：data\*.before-import-*、data\tmp
      3. 默认不含用户图片，需要时用 -IncludeUploads
      4. 不含版本控制产物（.gitkeep 等）—— 用户不该看到这些
      5. 结束前做一次交付校验：包内出现上面任何一类即删除该包并非零退出
#>

[CmdletBinding()]
param(
    [string]$OutDir = "",
    [switch]$IncludeUploads,
    [switch]$IncludeTests
)

$ErrorActionPreference = "Stop"

# 项目根 = 本脚本所在目录的上一级
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
if ([string]::IsNullOrEmpty($OutDir)) { $OutDir = Join-Path $Root "dist" }

Write-Host ""
Write-Host "=== ElationPic 打包 ===" -ForegroundColor Cyan
Write-Host "  项目根     : $Root"
Write-Host "  输出目录   : $OutDir"

if (-not (Test-Path $Root)) {
    Write-Host "[错误] 找不到项目根目录：$Root" -ForegroundColor Red
    exit 1
}

$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("elationpic-stage-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Path $stage -Force | Out-Null

$script:copied  = 0
$script:skipped = New-Object System.Collections.ArrayList

function Skip-Note {
    param([string]$What, [string]$Why)
    [void]$script:skipped.Add(("  - " + $What.PadRight(34) + $Why))
}

# 把项目里的一个文件/目录复制到暂存区
function Copy-ToStage {
    param([string]$RelPath)
    $src = Join-Path $Root $RelPath
    if (-not (Test-Path $src)) { return }
    $dst = Join-Path $stage $RelPath
    $dstDir = Split-Path -Parent $dst
    if ($dstDir -and -not (Test-Path $dstDir)) {
        New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
    }
    Copy-Item -Path $src -Destination $dst -Recurse -Force
    $script:copied++
}

# ------------------------------------------------------------------
# 1. 应用代码
# ------------------------------------------------------------------
Write-Host "  收集应用代码..." -ForegroundColor Gray
Copy-ToStage "src"
Copy-ToStage "deploy"

# public/ 需要逐项复制：uploads/ 里的用户图片默认不进包
$pubSrc = Join-Path $Root "public"
if (Test-Path $pubSrc) {
    $pubDst = Join-Path $stage "public"
    New-Item -ItemType Directory -Path $pubDst -Force | Out-Null
    Get-ChildItem $pubSrc -File | Where-Object { $_.Name -ne ".gitkeep" } | ForEach-Object {
        Copy-Item $_.FullName $pubDst -Force
        $script:copied++
    }
    $assetsSrc = Join-Path $pubSrc "assets"
    if (Test-Path $assetsSrc) {
        $assetsDst = Join-Path $pubDst "assets"
        New-Item -ItemType Directory -Path $assetsDst -Force | Out-Null
        Copy-Item (Join-Path $assetsSrc "*") $assetsDst -Recurse -Force
        $script:copied++
    }
}

# ------------------------------------------------------------------
# 2. 顶层文件与文档
# ------------------------------------------------------------------
Write-Host "  收集文档..." -ForegroundColor Gray
foreach ($f in @("README.md", "README.en.md", "LICENSE", "CONTRIBUTING.md", ".editorconfig")) {
    Copy-ToStage $f
}
$docsSrc = Join-Path $Root "docs"
if (Test-Path $docsSrc) {
    $docsDst = Join-Path $stage "docs"
    New-Item -ItemType Directory -Path $docsDst -Force | Out-Null
    Get-ChildItem $docsSrc -File | ForEach-Object {
        Copy-Item $_.FullName $docsDst -Force
        $script:copied++
    }
}

# ------------------------------------------------------------------
# 3. 测试脚本（不含大文件与截图）
# ------------------------------------------------------------------
Write-Host "  收集测试脚本..." -ForegroundColor Gray
$testsSrc = Join-Path $Root "tests"
if (Test-Path $testsSrc) {
    Get-ChildItem $testsSrc -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($testsSrc.Length + 1)
        if (-not $IncludeTests -and $rel -like "fixtures*") { return }
        if ($rel -like "shots*") { return }
        if ($rel -like "*.txt")    { return }
        if ($rel -like "__*")      { return }
        $dst = Join-Path (Join-Path $stage "tests") $rel
        $dstDir = Split-Path -Parent $dst
        if (-not (Test-Path $dstDir)) { New-Item -ItemType Directory -Path $dstDir -Force | Out-Null }
        Copy-Item $_.FullName $dst -Force
        $script:copied++
    }
}

# ------------------------------------------------------------------
# 4. data：只带模板与脚本
# ------------------------------------------------------------------
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

Skip-Note "data\config.php"          "含管理员密码哈希"
Skip-Note "data\database.sqlite"     "真实数据"
Skip-Note "data\logs\*"             "运行日志"
Skip-Note "data\*.before-import-*"   "导入回滚点"
Skip-Note "data\tmp\*"              "临时文件"

# ------------------------------------------------------------------
# 5. 空目录骨架
# ------------------------------------------------------------------
# 程序首次运行会自动创建这些目录，这里先建好，让解压后的结构一目了然。
# 注意：**不放 .gitkeep** —— 那是版本控制的产物，
# 用户下载的包里出现莫名其妙的点文件会让人困惑。
foreach ($d in @("data\logs", "data\sessions", "data\tmp", "backup", "public\uploads", "public\uploads\thumbs")) {
    $p = Join-Path $stage $d
    if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
}

# 可选：连同用户图片一起打包
if ($IncludeUploads) {
    Write-Host "  收集图片（-IncludeUploads）..." -ForegroundColor Gray
    $upSrc = Join-Path $Root "public\uploads"
    if (Test-Path $upSrc) {
        Get-ChildItem $upSrc -Recurse -File | Where-Object { $_.Name -ne ".gitkeep" } | ForEach-Object {
            $rel = $_.FullName.Substring($upSrc.Length + 1)
            $dst = Join-Path (Join-Path $stage "public\uploads") $rel
            $dstDir = Split-Path -Parent $dst
            if (-not (Test-Path $dstDir)) { New-Item -ItemType Directory -Path $dstDir -Force | Out-Null }
            Copy-Item $_.FullName $dst -Force
            $script:copied++
        }
    }
} else {
    Skip-Note "public\uploads\*"        "用户图片（需要时加 -IncludeUploads）"
}
# ------------------------------------------------------------------
# 6. 生成包内《快速上手》
# ------------------------------------------------------------------
$quickStart = @'
================================================================
ElationPic
轻量个人图床
================================================================

三步上手
--------

1) 把本目录放到网站根目录之外，再把网站根目录指向 public/

     本目录位置   /srv/elationpic
     网站根目录   /srv/elationpic/public     <-- 指向 public，不是本目录

   为什么必须这样做：data/ 里是数据库与配置（含管理员密码），
   它在 public/ 之外，Web 服务器就永远访问不到它。

2) 浏览器打开你的站点

   会进入安装向导。向导先做一次服务器环境检查
   （PHP 版本、扩展、目录权限、uploads 是否会被当作脚本执行等），
   检查不通过不会继续安装，并会说明具体缺什么。

3) 通过检查后填写站点信息与管理密码，完成安装


服务器要求
----------

   PHP      8.0.2 或更高
   扩展     pdo_sqlite、gd、fileinfo、json、zip
   数据库   无需安装，使用 PHP 自带的 SQLite
   Web      Nginx / Apache 均可

  安装向导会逐项检查这些，缺什么会直接告诉你。


最重要的一条安全设置
--------------------

  uploads/ 目录绝对不能被当作脚本执行。

  Nginx 中加入下面这段，并确保它位于通用的 location ~ \.php$ 之前：

      location ^~ /uploads/ {
          # 这里不要写 fastcgi_pass
      }

  现成片段见 deploy/FIX-uploads-no-exec.conf。
  安装向导也会实际探测这一点，配置不对会阻止安装。


目录说明
--------

   public/         网站根目录（只有这里对公网可见）
     index.php     首页
     setup.php     安装向导
     admin.php     后台管理
     uploads/      图片目录（禁止执行脚本）
     assets/       CSS / JS

   src/            程序代码
   data/           数据库、配置、日志、会话（不可公开访问）
     config.sample.php   配置模板
     config.php          安装后生成，含管理员密码，切勿外传
   docs/           设计与开发文档
   deploy/         服务器配置模板
   tests/          自检与测试脚本
   backup/         命令行备份的输出目录


日常维护
--------

   备份（数据库 + 站点设置 + 原图，打成一个 zip）：
       php data/backup.php
   也可以登录后台，在「备份」页直接下载。

   环境自检：
       powershell -ExecutionPolicy Bypass -File tests/preflight.ps1 -Base http://你的地址

   功能与安全用例：
       powershell -ExecutionPolicy Bypass -File tests/run-tests.ps1 -Base http://你的地址 -Password 你的密码


常见问题
--------

   Q: 打开站点 404
   A: 网站根目录没有指向 public/。检查服务器配置里的 root。

   Q: 安装向导提示目录不可写
   A: 给 data/ 与 public/uploads/ 写入权限（Windows 下通常是给 IIS_IUSRS，
      Linux 下是给 PHP 进程的属主）。

   Q: 提示 uploads 会执行 PHP
   A: 这是最需要修的一项。按上面「最重要的一条安全设置」改服务器配置。

   Q: 忘记管理员密码
   A: 删除 data/config.php 后重新打开站点，会回到安装向导。
      这不影响已上传的图片和数据库。

   Q: 图片里的拍摄地点会不会泄露
   A: 不会。上传时默认移除 EXIF/GPS 等元数据（只删元数据段，画面不变）。
      该开关可在后台「设置」中关闭。

'@

Set-Content -Path (Join-Path $stage "快速上手.txt") -Value $quickStart -Encoding UTF8

# ------------------------------------------------------------------
# 7. 压缩
# ------------------------------------------------------------------
$stamp   = Get-Date -Format "yyyyMMdd-HHmmss"
$pkgName = "elationpic-$stamp"
if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir -Force | Out-Null }
$zipPath = Join-Path $OutDir ($pkgName + ".zip")
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Write-Host ""
Write-Host "  正在压缩..." -ForegroundColor Gray

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $stage, $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)
Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue

# ------------------------------------------------------------------
# 8. 交付校验
# ------------------------------------------------------------------
# 这一步是最后一道防线：包内出现任何不该有的东西，就删除整个包。
# 宁可没有产物，也不能发布一个泄露密码或用户图片的包。
#
# 判定"是不是目录"很重要：CreateFromDirectory 会为**空目录**写出独立的
# 目录条目（形如 "public/uploads/thumbs/"），它们不含任何内容。
# 若把目录条目当成文件判断，会把空目录骨架误报成用户图片 ——
# 这会让打包在正常配置下直接失败。
$bad = New-Object System.Collections.ArrayList
Add-Type -AssemblyName System.IO.Compression.FileSystem
$z = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
foreach ($e in $z.Entries) {
    $n = $e.FullName

    # 目录条目：不含内容，不可能泄露任何东西，直接跳过。
    #
    # CreateFromDirectory 会为**空目录**写出独立条目。如何识别它：
    #   - .NET 在 Windows 上写成 "public\uploads\thumbs\"（反斜杠），
    #     其他平台用正斜杠 —— 两种都要判。
    #   - 更可靠的判据是 $e.Name -eq ""：Name 是路径最后一段且不含分隔符，
    #     目录条目没有最后一段，因此为空。这一点跨平台一致。
    # 三个条件取或：任一成立即视为目录，不会因平台差异而漏判。
    $isDir = ($e.Name -eq "") -or $n.EndsWith("/") -or $n.EndsWith("\")
    if ($isDir) { continue }

    if ($e.Name -eq ".gitkeep") {
        [void]$bad.Add($n + "  <- 版本控制占位文件"); continue
    }
    if ($n -eq "data/config.php")          { [void]$bad.Add($n + "  <- 含管理员密码哈希"); continue }
    if ($n -eq "data/database.sqlite")     { [void]$bad.Add($n + "  <- 真实数据"); continue }
    if ($n -like "data/logs/*")            { [void]$bad.Add($n + "  <- 运行日志"); continue }
    if ($n -like "data/tmp/*")             { [void]$bad.Add($n + "  <- 临时文件"); continue }
    if ($n -like "*before-import*")        { [void]$bad.Add($n + "  <- 导入回滚点"); continue }
    if ($n -like "public/uploads/*" -and -not $IncludeUploads) {
        [void]$bad.Add($n + "  <- 用户图片（未指定 -IncludeUploads）"); continue
    }
}
$entryCount = $z.Entries.Count
$z.Dispose()

if ($bad.Count -gt 0) {
    Write-Host ""
    Write-Host "  [失败] 包内出现了不该有的文件：" -ForegroundColor Red
    foreach ($b in $bad) { Write-Host ("    " + $b) -ForegroundColor Red }
    Write-Host "  已删除该包，避免误发布。" -ForegroundColor Red
    Remove-Item $zipPath -Force
    exit 1
}

# ------------------------------------------------------------------
# 9. 汇总
# ------------------------------------------------------------------
$zipSize = (Get-Item $zipPath).Length

Write-Host ""
Write-Host "=== 打包完成 ===" -ForegroundColor Green
Write-Host ("  文件   : " + $zipPath)
Write-Host ("  大小   : " + [math]::Round($zipSize / 1KB, 1) + " KB")
Write-Host ("  条目数 : " + $entryCount)

Write-Host "  已排除：" -ForegroundColor Yellow
foreach ($s in $script:skipped) { Write-Host $s }

Write-Host "  交付校验通过：包内无配置、数据库、日志、回滚点、版本控制产物" -ForegroundColor Green
if (-not $IncludeUploads) {
    Write-Host "  用户图片未包含；整站迁移请加 -IncludeUploads" -ForegroundColor Yellow
}
Write-Host ""
Write-Host "  解压后把网站根目录指向 public/ 即可安装。"
Write-Host ""