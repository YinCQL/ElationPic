# ElationPic — 极简个人 PHP 图床 设计文档

> 对应《极简图床设计要求.md》§43 阶段 1–3
> 目标运行时：**PHP 8.0.2 NTS · Nginx 1.25.2 · SQLite 3（PDO）**
> 本文件为冻结契约，实现代码以本文为准。

---

## 1. 需求分析与硬约束

设计判据（取舍时按此排序）：安全 > 简单 > 快速/低占用 > 功能数量。

| # | 约束 | 来源 | 验证方式 |
|---|---|---|---|
| C1 | 只能用 PHP 8.0.2 存在的语法与函数 | §3 | `php -l` + 见 §4 红线清单 |
| C2 | 首页公开只读，上传/删除严格限定管理员 | §11 | 匿名访问 `upload.php`/`delete.php` 必须被拒 |
| C3 | 图片直链由 Nginx 直接返回，PHP 不读图输出 | §8 §39 | `uploads/` 不经过 PHP-FPM |
| C4 | 上传 5 层防护：MIME 白名单/扩展名白名单/内容验证/服务端命名/目录禁执行 | §14 | 见 §6.2 |
| C5 | 上传目录在 Nginx 层不可执行 PHP | §16 §30 | 上传 `.php` 后请求它必须 403 |
| C6 | 数据库与 config.php 不可经 HTTP 访问 | §17 | 请求 `/data/database.sqlite` 必须被拒 |
| C7 | 单文件上限 10 MB（可调 20 MB），程序内自校验 | §18 | 11 MB 文件必须被拒 |
| C8 | 默认不压缩/不转码/不裁剪/不加水印 | §20 | 原图字节完全一致 |
| C9 | 删除必须 POST + CSRF，禁止 GET 触发 | §23 | `GET /delete.php?id=1` 不得删除数据 |
| C10 | 全部 SQL 使用 PDO 预处理 | §25 | 代码中无拼接 SQL |
| C11 | 全部用户可控输出 htmlspecialchars 转义 | §26 | 见 §7 |
| C12 | 用户输入绝不参与服务端路径拼接 | §27 | 文件名由 random_bytes 生成 |
| C13 | 生产不向用户显示错误细节 | §37 | display_errors=Off + 固定文案 |
| C14 | 日志不记录密码/Session Cookie/完整 Token | §38 | 审计日志内容 |
| C15 | 不引入 §40 任何框架/JS 库/Redis/Docker | §40 | 无 composer 依赖 |
| C16 | 首页不一次性加载全部原图 | §7 | 首页只引用 thumbs/ |
| C17 | 未登录用户看不到任何管理入口或信息 | §35 | 首页 HTML 无上传/删除字样 |

**威胁模型（§42）**：攻击者已知站点 URL 与 PHP 文件名，能改任意请求，能伪造扩展名上传恶意文件，能尝试 SQLi/XSS/CSRF/目录穿越/PHP 上传/暴力破解。

**底线：任何情况下都不能让攻击者通过某个 URL 触发任意 PHP 代码执行。**

### 非目标

注册、多用户、权限系统、评论、收藏、标签、相册、REST API、验证码、找回密码、OAuth、CDN、图片编辑、水印、自动转码、EXIF 面板、后台图表、批量操作。

---

## 2. 项目结构

站点根指向 `public/`；`data/` 与 `public/` **平级**，物理位于 Web 根之外。

```text
img/                          <- 项目根（非 Web 根）
├── public/                   <- ★ 站点根 DocumentRoot
│   ├── index.php             首页（公开 Gallery）
│   ├── login.php             管理员登录
│   ├── logout.php            登出（POST）
│   ├── admin.php             管理后台（需登录）
│   ├── settings.php          站点设置（需登录 + CSRF）
│   ├── password.php          修改管理员密码（需登录 + CSRF + 校验当前密码）
│   ├── backup.php            备份与恢复页 + 维护区（需登录）
│   ├── rebuild_thumbs.php    重建缩略图接口（JSON，需登录 + CSRF）
│   ├── export.php            导出数据库（VACUUM INTO 一致快照）
│   ├── import.php            导入数据库（确认短语 + 校验 + 回滚点 + 原子替换）
│   ├── upload.php            上传接口（POST，需登录 + CSRF）
│   ├── delete.php            删除接口（POST，需登录 + CSRF）
│   ├── delete_many.php       批量删除接口（JSON，需登录 + CSRF）
│   ├── backup_delete.php     删除备份文件（JSON，路径安全）
│   ├── error.php             统一错误页
│   ├── assets/
│   │   ├── css/              样式（按层拆分，见下）
│   │   │   ├── 1-base.css      基础：变量、排版、组件
│   │   │   ├── 2-polish.css    视觉规格第二版（覆盖层）
│   │   │   └── 3-theme.css     语义色与深色模式
│   │   └── app.js            少量原生 JS
│   └── uploads/              ★ 公开图片目录，Nginx 直接返回
│       └── thumbs/           GD 缩略图
├── data/                     <- Web 根之外，敏感数据
│   ├── config.php            ★ 需人工创建
│   ├── config.sample.php     配置模板
│   ├── database.sqlite       首次运行自动建表
│   ├── install.php           CLI 一次性初始化
│   ├── backup.php            CLI 数据库备份（VACUUM INTO）
│   └── logs/app.log
├── src/                      <- 扁平函数库，非框架
│   ├── bootstrap.php         统一入口
│   ├── helpers.php           e()/json_out()/log_event() 等
│   ├── db.php                PDO + 建表
│   ├── auth.php              会话与登录限速
│   ├── csrf.php              CSRF token
│   ├── images.php            图片数据访问
│   ├── upload.php            上传校验链
│   ├── thumbnails.php        GD 缩略图
│   └── views/
│       ├── header.php
│       ├── footer.php
│       └── card.php
├── deploy/                   <- 阶段 10
│   ├── nginx.conf
│   ├── php.ini
│   └── README-DEPLOY.md
├── tests/                    <- 阶段 12
├── docs/
│   ├── DESIGN.md             本文
│   ├── DEVELOPMENT.md        开发说明（结构 / 数据库 / 契约 / 测试）
│   └── TEST-PLAN.md          阶段 12
└── README.md                 项目说明
```

不引入 Composer autoload、命名空间、MVC 三层。单管理员 + 约 6 个页面，抽象层维护成本高于收益（§44）。

### 2.1 部署模式与 `base_path`

站点根**必须**指向 `public/`。但项目可能被放在 Web 根的子目录中
（例如站点根是某个上级目录，而项目位于它的子目录里）。
此时必须设置 `base_path`，否则所有绝对 URL 都会解析错误。

| 模式 | 站点根 | `base_path` | `data/` 保护 |
|---|---|---|---|
| A（推荐） | `项目/public` | `''` | **物理隔离**——Web 根之外 |
| B | 上级目录 | `'/elationpic/public'` | 依赖 Web 服务器规则屏蔽 |

所有站点内 URL 一律经 `url()` 生成；`base_path` **只来自配置，绝不取自请求头**，
因此不存在 Host 注入或开放重定向风险。详见 `deploy/README-DEPLOY.md`。

---

## 3. SQLite 数据库

### 3.1 为什么不用 MySQL（§2 复审）

| 判据 | 本项目 | 结论 |
|---|---|---|
| 并发 | 单管理员，峰值 ≈ 1 | SQLite 足够 |
| 数据量 | 数千行元数据，< 1 MB | SQLite 足够 |
| 写入频率 | 仅上传/删除 | SQLite 足够 |
| 内存占用 | 无独立进程 | SQLite **优** |
| 部署复杂度 | 零配置单文件 | SQLite **优** |
| 攻击面 | 无 3306、无 DB 账号 | SQLite **优** |

**结论：使用 SQLite，不引入 MySQL。**

### 3.2 连接配置

```php
$pdo = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA synchronous = NORMAL');
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
```

> **WAL 注意**：会产生 `database.sqlite-wal` / `-shm`，Nginx 必须一并屏蔽 `*.sqlite*`。

### 3.3 表结构

```sql
CREATE TABLE IF NOT EXISTS images (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL UNIQUE,
    original_name TEXT    NOT NULL,
    mime          TEXT    NOT NULL,
    size          INTEGER NOT NULL,
    width         INTEGER NOT NULL DEFAULT 0,
    height        INTEGER NOT NULL DEFAULT 0,
    sha256        TEXT    NOT NULL DEFAULT '',
    thumb         INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT    NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_images_filename   ON images(filename);
CREATE INDEX        IF NOT EXISTS idx_images_created_at ON images(created_at DESC);
-- 不建 sha256 索引：无排重需求（§1），避免每次上传多维护一棵索引树
```

| 字段 | 说明 |
|---|---|
| `filename` | 服务端随机名，直链路径的唯一来源 |
| `original_name` | 用户原始名，**仅展示，必须转义** |
| `mime` | finfo 真实 MIME |
| `width`/`height` | getimagesize 结果，失败置 0 |
| `sha256` | 内容哈希，§21 允许的 hash 字段 |
| `thumb` | 缩略图存在标志，避免每次 stat 文件系统 |
| `created_at` | UTC 定长字符串，字典序即时间序 |

**不建** `users`/`roles`/`permissions`/`comments`/`tags`/`albums`/`favorites`/`views`/`ip`/`user_agent`。

### 3.4 登录限速存储

不建表。限速状态存 `data/logs/login_attempts.json`：无 Redis（§28 禁止）、Session 计数可被丢弃 Cookie 绕过、SQLite 写入会在暴力破解时产生大量事务。

---

## 4. PHP 8.0.2 兼容性红线

**以下 8.1+ 特性一律禁用。**

| 禁用项 | 版本 | 8.0.2 替代 |
|---|---|---|
| `readonly` 属性 | 8.1 | `private`，或不用类 |
| `enum` | 8.1 | `const` 字符串常量 |
| `never` 返回类型 | 8.1 | `void` |
| 纯交集类型 `A&B` | 8.1 | 不用类型化属性 |
| `new` in initializers | 8.1 | 构造函数内赋值 |
| First-class callable `f(...)` | 8.1 | `'f'` 或 `Closure::fromCallable` |
| `array_is_list()` | 8.1 | `$a === array_values($a)` |
| 字符串键 array unpack | 8.1 | `array_merge()` |
| `fsync()` | 8.1 | `fflush()` |
| `readonly class` | 8.2 | 不用类 |
| DNF 类型 | 8.2 | 不用复杂联合 |
| `null`/`false`/`true` 独立类型 | 8.2 | docblock |
| `Random\Randomizer` | 8.2 | `random_bytes()`/`random_int()` |
| `json_validate()` | 8.3 | `json_decode() !== null` |
| `#[\Override]` | 8.3 | 不用 |
| Typed class constants | 8.3 | 不加类型 |
| `mb_str_pad()` | 8.3 | `str_pad()` |
| Property hooks | 8.4 | 不用 |
| `array_find()` 系列 | 8.4 | 手写 `foreach` |

### 明确允许（8.0 已有）

`match`、构造器属性提升、`?->`、命名参数、`str_contains`/`str_starts_with`/`str_ends_with`、`throw` 表达式、`mixed`、尾逗号、联合类型、`static` 返回类型、`random_bytes`、`password_hash`、`finfo_*`、`getimagesize`、GD 函数、`hash_file`。

### 编码约定

- 首行 `<?php` 后跟 `declare(strict_types=1);`
- UTF-8 **无 BOM**，换行 LF
- 不写 `?>` 结束标签
- 时间统一 UTC：`gmdate('Y-m-d H:i:s')`

---

## 5. 冻结契约

### 5.1 `data/config.php` 返回结构

```php
return [
    'admin_password_hash' => '$2y$10$...',   // 必须，且必须是字符串形态的哈希
    'site_name'           => 'ElationPic',
    'site_description'    => 'A lightweight personal image hosting system.',
    'timezone'            => 'Asia/Shanghai',
    'base_path'           => '',             // 子目录部署时如 '/elationpic/public'，见 §2.1
    'per_page'            => 20,
    'max_file_bytes'      => 10485760,       // 10 MB；改 20 MB 用 20971520
    'thumb_max_edge'      => 480,            // 0 = 关闭缩略图
    'strip_metadata'      => true,           // 上传时移除 EXIF/GPS（见 §4.1）
    'force_https'         => true,           // ★ 本地 HTTP 调试必须改 false
    'login_max_attempts'  => 5,
    'login_window_secs'   => 900,
    'login_lockout_secs'  => 900,
    'log_level'           => 'info',
];
```

**默认值来源**：`data/install.php` 内嵌了同一组默认值（不依赖 `config.sample.php` 存在），
`src/bootstrap.php` 亦内嵌一份兜底默认（除 `admin_password_hash` 外全部可选）。
三者必须保持一致——修改时请同步。

### 5.2 路径常量

```php
define('APP_ROOT',   dirname(__DIR__));
define('DATA_DIR',   APP_ROOT . '/data');
define('PUBLIC_DIR', APP_ROOT . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');
define('THUMB_DIR',  UPLOAD_DIR . '/thumbs');
define('LOG_FILE',   DATA_DIR . '/logs/app.log');
define('DB_FILE',    DATA_DIR . '/database.sqlite');
```

### 5.3 共享函数签名（与实现同步）

> 本节于第十二轮审计时对照实际代码逐条核对并更正。
> 此前版本存在 6 处脱节（`auth_login` 返回类型、`thumb_create` 参数、
> 以及 4 个新增函数未记录）。

```php
// helpers.php
function e(?string $s): string;
function json_out(array $payload, int $status = 200): void;
function fail_message(): string;
function log_event(string $level, string $event, array $ctx = []): void;
function client_ip(): string;
function is_https(): bool;
function format_bytes(int $bytes): string;
function format_local_time(string $utc): string;
function url(string $path = '/'): string;          // 支持 base_path 子目录部署
function safe_filename(string $name): ?string;
function random_filename(string $ext): string;
function image_url(string $filename): string;      // 经 url() 拼接
function thumb_url(string $filename): string;      // 经 url() 拼接

// db.php
function db(): PDO;
function db_migrate(PDO $pdo): void;

// auth.php
function auth_is_logged_in(): bool;
function auth_login(string $password): array;      // ['ok'=>bool,'locked'=>int,'message'=>string]
function auth_logout(): void;
function require_admin(): void;                    // HTML 页 302；JSON 请求 403
function require_admin_json(): void;               // API 端点专用，绝不重定向
function wants_json(): bool;
function auth_is_locked(): int;                    // 返回剩余锁定秒数，0 表示未锁定
function auth_record_failure(): void;
function auth_clear_failures(): void;
function auth_rate_file(): string;
function auth_rate_load(): array;
function auth_rate_save(array $data): void;
function auth_rate_gc(array $data, int $now, int $window): array;

// csrf.php
function csrf_token(): string;
function csrf_verify(?string $token): bool;
function csrf_field(): string;
function csrf_from_request(): ?string;             // POST 字段或 X-CSRF-Token 头
function csrf_require_post(): void;                // 非 POST -> 405；CSRF 失败 -> 403

// images.php
function images_page(int $page, int $perPage): array;
function images_count(): int;
function image_find(int $id): ?array;
function image_insert(array $meta): int;
function image_delete(int $id): bool;
// 注：image_find_by_hash() 已于第十二轮移除（从未被调用，属 §44 禁止的过度设计）

// upload.php
function handle_upload(array $file, array $cfg): array;
function upload_allowed_types(): array;
function upload_imagetype_to_mime(int $type): ?string;
function upload_ini_bytes(string $key): int;
function upload_sanitize_original_name(string $name): string;
function upload_remove_files(string $filename): void;

// helpers.php（第三十三轮新增）
function absolute_url(string $path = '/'): string;  // 含协议+主机的绝对地址，供"复制直链"
function display_name(string $name): string;        // 展示用文件名（去掉图片扩展名）

// bootstrap.php（第三十三轮新增）
function cfg_reload(): void;                        // 保存配置后重载，避免旧值渲染

// images.php（第四十九轮新增）
function images_stats(): array;                     // ['count','bytes','thumbs','no_thumb']
function images_recent(int $limit = 6): array;
function images_search(string $q, int $page, int $perPage): array;  // LIKE 通配符已转义
function images_find_many(array $ids): array;       // 以 id 为键，上限 500

// images.php（第六十五轮新增）
function images_sort_sql(string $sort): string;
//   排序白名单：请求值 -> 固定 SQL 片段。
//   ORDER BY 无法用占位符绑定，因此用户输入只用于查表，永不进入 SQL。
function images_sort_options(): array;
//   ['new'=>'最新上传', 'old'=>..., 'big'=>..., 'small'=>..., 'name'=>...]
function images_consistency_check(int $limit = 200): array;
//   一致性自检：只扫描一次目录 + 一次查询，内存求差集（实测 0.10 ms）
function images_purge_orphans(array $names, int $max = 500): array;
//   删除孤儿文件；命名规则二次校验 + basename() 兜底，防路径穿越

// thumbnails.php（第五十九轮新增）
function thumb_rebuild_batch(int $offset, int $limit): array;
//   分批重建缩略图。命令行脚本、网页接口与后台按钮共用同一实现。
//   返回 ['processed','built','skipped','missing','failed','next','total','done']

// settings.php（第三十六轮新增）
//   站点配置的读取与安全改写。安装向导与后台设置页共用同一实现 ——
//   写坏配置会让站点无法启动，因此这段逻辑只能有一份。
function settings_allowed_keys(): array;            // 键白名单（防任意键写入）
function settings_config_file(): string;
function settings_load(): array;
function settings_coerce(string $key, $value);      // 按类型收敛，非法返回 null
function settings_ranges(): array;                  // 各 int 键的取值区间
function settings_validate(array $input, array $current): array;  // ['ok','cfg','errors']
function settings_save(array $cfg): bool;           // 原子写入（临时文件 + rename）

// thumbnails.php
function thumb_create(string $srcPath, string $destPath, int $maxEdge,
                      int $srcW = 0, int $srcH = 0): bool;
```

**实现约定**：所有函数均为全局函数（无命名空间、无类），与 §2「扁平函数库，非框架」一致。

### 5.4 表单与 DOM 契约

| 契约 | 值 |
|---|---|
| 登录 | POST `login.php`，字段 `password`、`csrf_token` |
| 上传 | POST `upload.php`，字段 `image`、`csrf_token` |
| 删除 | POST `delete.php`，字段 `id`、`csrf_token` |
| 登出 | **POST** `logout.php`，字段 `csrf_token` |
| CSRF 字段名 | `csrf_token` |
| CSRF 请求头 | `X-CSRF-Token` |
| 成功 JSON | `{"ok":true,"data":{...}}` |
| 失败 JSON | `{"ok":false,"error":"..."}` |
| 卡片容器 | `article.card` |
| 缩略图 | `img.thumb` + `loading="lazy"` |

---

## 6. 安全设计

### 6.1 权限矩阵（§11）

| 操作 | 未登录 | 已登录 |
|---|---:|---:|
| 查看首页 | ✓ | ✓ |
| 查看图片/直链 | ✓ | ✓ |
| 上传 | ✗ | ✓ |
| 删除 | ✗ | ✓ |
| 进入后台 | ✗ | ✓ |

每个入口文件独立调用 `require_admin()`，不依赖前端隐藏按钮。

### 6.2 上传链路（§14 五层 / §19 九步）

| 步 | 动作 | 失败 |
|---|---|---|
| 1 | `$_FILES['error'] === UPLOAD_ERR_OK` | 拒绝 |
| 2 | `is_uploaded_file()` | 拒绝（防伪造路径） |
| 3 | 大小 `> 0` 且 `<= max_file_bytes` | 拒绝 |
| 4 | **MIME 白名单**：`finfo_file()` ∈ {jpeg,png,gif,webp} | 拒绝（不信 `$_FILES['type']`） |
| 5 | **扩展名白名单**：由 MIME 反查，忽略用户提供的名字 | 不可能失败 |
| 6 | **内容验证**：`getimagesize()` 成功且 type 与 MIME 一致 | 拒绝 |
| 7 | 内容哈希 + `random_filename($ext)` | — |
| 8 | `move_uploaded_file()` 落盘，落盘后复核 | 删临时文件，拒绝 |
| 9 | 写库 | 回滚已落盘文件，拒绝 |

不做 `imagecreatefromstring` 重编码（§20 保持原图 + 省 CPU）。唯一例外是缩略图，需限制像素总数。

### 6.3 目录与文件安全

- 文件名 = `bin2hex(random_bytes(16))` + 白名单扩展名，不可预测、无路径分隔符。
- Nginx 用 `location ^~ /uploads/`——**`^~` 修饰符**使其前缀匹配优先于同级正则 location，
  是该保护生效的关键；块内显式 `location ~* \.(php|phar|...)$ { return 403; }`，
  且**整个块内不出现 `fastcgi_pass`**，因此该目录在架构上不可能进入 PHP-FPM。
- `data/` 在 Web 根之外，Nginx 额外屏蔽 `\.(sqlite|db|log)$`、`config\.php$`、`\.ht`、`\.git`。
- 全局 `autoindex off`。

### 6.4 CSRF（§24）

`bin2hex(random_bytes(32))` 存 `$_SESSION['csrf_token']`；校验用 `hash_equals`。覆盖登录、上传、删除、登出。

### 6.5 资源类攻击对策

| 攻击 | 对策 |
|---|---|
| 巨像素图（解压炸弹） | 缩略图前检查 `w*h <= 50,000,000`，超限则跳过缩略图，原图仍保存 |
| 大文件耗尽内存 | 大小检查在 `getimagesize` 之前；`memory_limit=128M` |
| 暴力破解 | 文件型限速 + 失败时 `usleep(300000)` |
| Session 固定 | 登录成功 `session_regenerate_id(true)` |
| Session 窃取 | `HttpOnly` + `SameSite=Lax` + HTTPS 下 `Secure` + `use_strict_mode=1` |

### 6.6 错误与日志（§37 §38）

- `display_errors=Off`、`log_errors=On`、`error_log` 指向 Web 根外。
- 统一异常/错误/shutdown 处理器，用户只看到「操作失败，请稍后重试。」
- 记录：`login_success`/`login_failed`/`login_locked`/`upload_failed`/`delete_failed`/`csrf_failed`/`forbidden_request`。
- **绝不记录**：密码、`$_SESSION` 内容、Session ID、CSRF token 全值。

---

## 7. 输出契约（反 XSS）

| 输出位置 | 处理 |
|---|---|
| `original_name` | `e()` —— **最危险字段** |
| `filename` | `e()` + `safe_filename()` 校验 |
| 图片 URL | 由校验过的 `filename` 拼接 |
| 错误消息 | 只用固定文案常量 |
| 分页参数 | `(int)` 强转 |

CSS/JS 全为静态文件，不在 HTML 内联任何动态字符串，因此 CSP 可收紧为 `script-src 'self'`（无 `unsafe-inline`）。

---

## 8. 性能设计（§7 §39）

| 目标 | 手段 |
|---|---|
| 直链不经过 PHP | Nginx 直接返回 `public/uploads/` |
| 首页不加载原图 | 首页只引用 `thumbs/`，回退时加 `loading="lazy"` |
| 不扫描 uploads | 列表全部来自 SQLite，代码中无 `scandir`/`glob` 遍历上传目录 |
| 不重复计算 | 尺寸/哈希在写入时一次算好 |
| 首页分页 | `LIMIT/OFFSET` |
| 静态缓存 | 随机不可变名 -> `max-age=2592000` |
| 动态页不缓存 | 后台/登录 `no-store` |

---

## 9. §46 交付映射

| # | 要求 | 位置 |
|---|---|---|
| 1 | 目录结构 | README + §2 |
| 2 | 数据库结构 | §3 + `src/db.php` |
| 3 | PHP 文件说明 | README |
| 4 | Nginx 配置 | `deploy/nginx.conf` |
| 5 | PHP 配置要求 | `deploy/php.ini` |
| 6 | 密码初始化 | `data/install.php` + README |
| 7 | 部署方法 | `deploy/README-DEPLOY.md` + `README.md` |
| 8 | 安全措施 | `docs/DEVELOPMENT.md` §4–§5 |
| 9 | 测试结果 | `docs/TEST-PLAN.md` |
| 10 | 已知限制 | README 末节 |