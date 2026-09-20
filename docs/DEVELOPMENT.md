# 开发说明

面向想了解实现细节或参与开发的人。快速上手请看仓库根目录的 `README.md`。

---

## 1. 技术选择

| 选择 | 原因 |
|---|---|
| PHP 8.0.2 | 目标运行环境；不使用 8.1+ 语法（`readonly`、`enum`、`never` 等） |
| SQLite | 单管理员、低并发、数据量小。零配置，无数据库端口与账号可被攻击 |
| 无框架、无 Composer | 直接 FTP 上传即可运行，不需要构建步骤 |
| 原生前端 | 无构建、无依赖，改完刷新即可生效 |
| `public/` + `data/` 分离 | `data/` 位于 Web 根之外，HTTP 物理上无法到达 |

---

## 2. 目录结构

```text
public/          网站根目录（DocumentRoot 指向这里）
  index.php      首页（公开）
  setup.php      安装向导
  admin.php      后台管理
  upload.php     上传接口
  assets/        CSS / JS
  uploads/       图片目录（不可执行脚本）

src/             程序代码（不在网站根内）
  bootstrap.php  统一引导：常量、配置、错误处理、会话加固
  helpers.php    转义、日志、路径白名单、格式化、绝对地址
  db.php         PDO 连接与建表
  auth.php       认证、登录限速、权限守卫
  csrf.php       CSRF token
  settings.php   配置读取、校验、原子写入
  backup.php     备份包构建与解析
  images.php     图片数据读写、排序白名单、一致性检查
  upload.php     上传校验链
  thumbnails.php GD 缩略图与批量重建
  views/         页面片段

data/            Web 根之外（不可通过 HTTP 访问）
  config.php     运行时配置（安装时生成，不在版本库中）
  database.sqlite
  logs/ sessions/ tmp/
```

---

## 3. 数据库

单表 `images`：

```sql
CREATE TABLE images (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL UNIQUE,   -- 服务端生成的随机文件名
    original_name TEXT    NOT NULL,          -- 用户上传时的原始文件名
    mime          TEXT    NOT NULL,
    size          INTEGER NOT NULL,
    width         INTEGER NOT NULL DEFAULT 0,
    height        INTEGER NOT NULL DEFAULT 0,
    sha256        TEXT    NOT NULL DEFAULT '',
    thumb         INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT    NOT NULL
);
CREATE UNIQUE INDEX idx_images_filename   ON images(filename);
CREATE INDEX        idx_images_created_at ON images(created_at DESC);
```

连接设置：

```php
PDO::ATTR_ERRMODE          => ERRMODE_EXCEPTION
PDO::ATTR_EMULATE_PREPARES => false   // 真实服务端预处理
PRAGMA journal_mode = WAL              // 读写不互斥
PRAGMA synchronous  = NORMAL
PRAGMA foreign_keys = ON
```

> WAL 模式下**直接复制 `database.sqlite` 会丢失最近的事务**。
> 备份必须用 `VACUUM INTO`（`data/backup.php` 已实现）。

---

## 4. 上传校验链

上传是最主要的攻击面，校验分多层，任一层失败即拒绝：

| 层 | 检查 |
|---|---|
| 1 | 请求方法、会话、CSRF |
| 2 | `$_FILES` 错误码与 `is_uploaded_file()` |
| 3 | 大小上限（配置值，且不超过 PHP 自身限制） |
| 4 | `finfo_file()` 检测真实 MIME，比对白名单 |
| 5 | 扩展名由**检测到的 MIME 反推**，不信任客户端文件名 |
| 6 | `getimagesize()` 确认是可解析的图片 |
| 7 | 像素总数上限（防解压炸弹） |
| 8 | 文件名用 `bin2hex(random_bytes(16))` 重新生成 |
| 9 | 剥离隐私元数据（见下节） |
| 10 | 落盘后复核尺寸，确认文件仍可解析 |

**为什么扩展名来自 MIME 而不是文件名**：`evil.php.jpg` 这类双重扩展名，
如果按客户端文件名取扩展名，就可能得到一个可执行的 `.php`。
反过来从检测到的 MIME 反推，攻击者无法影响结果。

---

## 4.1 隐私元数据剥离

手机拍摄的照片通常带 GPS 坐标、拍摄时间、设备型号。图床的直链是公开的，
**等于把拍摄地点一起公开了** —— 这是不易察觉、后果却很实在的问题。
因此上传时默认移除这些元数据（可在后台关闭）。

### 为什么不用 GD 重编码

最直接的做法是 `imagecreatefromstring()` 读进来再 `imagejpeg()` 写出去，
这确实能去掉全部元数据。但它**重新压缩了像素** ——
实测质量 92 时体积变化约 -11%，而且每次处理都会累积一代损失。
这违背了"原图不做处理"的承诺。

### 实际做法：只删元数据段

JPEG / PNG / WebP 都是**分段容器**，元数据位于独立的段里。
把这些段整段移除，剩下的压缩像素数据**一个字节都不会变**。
这正是 `jpegtran -copy none` 的思路。

| 格式 | 移除的段 | 保留的段 |
|---|---|---|
| JPEG | APP1（EXIF/XMP）、APP3–APP15（IPTC 等）、COM 注释 | APP0（JFIF）、APP2（**ICC 颜色配置**）、DQT/DHT/SOF |
| PNG | `tEXt`、`zTXt`、`iTXt`、`eXIf` | `IHDR`、`IDAT` 及所有颜色相关块 |
| WebP | `EXIF`、`XMP ` 块 | `VP8 `/`VP8L`/`VP8X` 等图像数据块 |

**ICC 颜色配置必须保留**：删掉它会导致颜色显示不一致，是"清理元数据"常见的过度操作。

WebP 还要注意：删除块之后 RIFF 头里记录的**总长度必须同步更新**，否则文件损坏。

### 失败时的行为

解析不了就**原样保留**并记日志（`metadata_strip_failed`）。
宁可留下元数据，也不能损坏用户的图片。

实测（真实 HTTP 上传）：

```text
输入 65465 字节，含 EXIF/GPS
输出 65266 字节
  EXIF/GPS 已移除 : 是
  像素逐字节一致  : 是（未重压缩）
  尺寸 640x480    : 不变
```

---

## 5. 输出与安全约定

| 约定 | 说明 |
|---|---|
| 所有输出经 `e()` 转义 | 防 XSS |
| 所有 SQL 用预处理语句 | 无字符串拼接 |
| `ORDER BY` 用白名单映射 | 排序键无法绑定占位符，故查表得到固定片段 |
| 文件名经 `safe_filename()` 校验 | 只允许 `[a-f0-9]{32}.扩展名` |
| 绝对地址经 Host 校验 | 防 Host 注入与开放重定向 |
| 变更操作要求 POST + CSRF | 防 CSRF |

---

## 6. 前端约定

| 约定 | 原因 |
|---|---|
| 事件委托绑定到 `document` | 列表刷新会替换 `innerHTML`，绑在具体元素上的监听器会随之销毁 |
| 元素每次重新查询，不缓存引用 | 同上：缓存的引用会指向已脱离文档的节点 |
| 不用 `requestAnimationFrame` 做状态节流 | rAF 在页面不渲染时可能不执行，状态会卡住 |
| 主题脚本同步置于 `<head>`、在样式表之前 | 异步执行会导致刷新时闪一下浅色 |
| 不把 `img.src` 设为空字符串 | 浏览器会把它解析为页面地址并发起无效请求 |

---

## 7. 测试

```powershell
# 环境预检：PHP 版本 / 扩展 / 语法 / 权限 / 敏感文件 / 磁盘 / uploads 可执行性
powershell -ExecutionPolicy Bypass -File tests\preflight.ps1 -Base http://你的地址

# 功能与安全用例
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -Base http://你的地址 -Password 你的密码

# 文档完整性检查
powershell -ExecutionPolicy Bypass -File tests\check-docs.ps1

# uploads 是否可执行 PHP
powershell -ExecutionPolicy Bypass -File tests\check-uploads-exec.ps1
```

**造测试素材**（约 22 MB，不入库）：

```bash
php tests/make-fixtures.php
```

生成的素材含恶意样本（`shell.jpg`、`evil.php.jpg`、双重扩展名等），
用于验证上传链能否正确拒绝。小体积的样本已入库，大文件需现生成。

测试用例覆盖：可访问性、登录、权限、安全（注入 / 遍历 / XSS / CSRF）、
上传、删除、限速、设置、备份、维护工具、主题与排序。

---

## 8. 已知限制

| # | 限制 | 说明 |
|---|---|---|
| 1 | 单管理员 | 无多用户与权限分级，拿到密码即全部权限 |
| 2 | GIF 不剥离元数据 | GIF 的块结构与 JPEG/PNG/WebP 差异较大，当前未处理 |
| 3 | 首页公开 | 需要私密须自行在服务器层加限制 |
| 4 | 改了缩略图尺寸需手动重建 | 后台「备份 → 维护」提供一键重建 |
| 5 | 无图片总量上限 | 磁盘水位在后台可见，但不会阻止上传 |
| 6 | 测试脚本以 Windows PowerShell 为主 | 核心程序跨平台，测试脚本偏 Windows |

---

## 9. 编码约定

| 约定 | 原因 |
|---|---|
| PHP 文件用 `declare(strict_types=1)` | 避免隐式类型转换 |
| 注释说明**为什么**，而非**做了什么** | 代码本身能说明做了什么 |
| `.ps1` 脚本保持纯 ASCII，或保存为 UTF-8 **带 BOM** | Windows PowerShell 5.1 无 BOM 时按 ANSI 解读，中文会乱码 |
| 新增 `.ps1` 中的正则注意转义 | 经工具链传递时 `\s+` 可能悄悄变成 `s+`（合法但永不匹配） |
