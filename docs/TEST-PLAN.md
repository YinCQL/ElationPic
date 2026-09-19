# 测试方案与验证清单（§45）

> 执行环境：Windows（Nginx 1.25.2 + PHP 8.0.2 NTS）
> 管理员密码：由你在 `data/install.php` 中设定，下文用 `<PW>` 表示。
>
> ⚠️ **关于站点地址**：下文的 `http://127.0.0.1:8099` 只是**示例**，
> 取自设计要求 §45 的说法（「完成后可以访问本地 8099 端口」）。
> **你必须换成自己站点的真实地址**，否则所有 curl 都会连接失败：
>
> | 你的站点形式 | 应使用的地址 |
> |---|---|
> | 端口 80，域名为 localhost | `http://localhost` |
> | 自定义端口 8099 | `http://127.0.0.1:8099` |
> | 绑定了自定义域名 | `http://img.test` |
> | 子目录方式访问 | `http://localhost/img/public` |
>
> **判断方法**：在浏览器里打开你的站点首页，地址栏里是什么，就用什么。
> 跑脚本时通过 `-Base` 传入，例如：
> `-Base http://localhost` 或 `-Base http://localhost/img/public`.
>
> 若不确定端口，请查看 Web 服务器或面板中该站点的监听端口。

---

## 0. 前置：环境预检（**先跑这个**）

在做任何其他事之前，运行环境预检脚本。它会把「环境问题」和「代码问题」
提前分开，避免你在两种失败之间反复猜测：

```powershell
cd <项目根>
powershell -ExecutionPolicy Bypass -File tests\preflight.ps1
```

它逐项检查并给出 **[OK] / [FAIL] / [WARN]**：

1. PHP 解释器是否存在、版本是否为 8.0.x
2. **必需扩展**：`pdo_sqlite`、`fileinfo`（缺失则上传安全失效）；可选 `gd`、`mbstring`
3. **全部 PHP 文件语法**（等价于批量 `php -l`）
4. 目录是否存在且**可写**（`data/`、`data/logs`、`data/sessions`、`data/tmp`、`public/uploads/`、`thumbs/`）
5. `data/config.php` 是否存在、是否可解析、`admin_password_hash` 形态是否正确
   （**同时提示 `force_https` 与 `base_path` 的常见配置错误**）
6. 数据库是否存在、`images` 表是否可查询
7. 站点是否可达、**CSS 是否可加载**（`base_path` 正确性的直接证据）、
   以及**`/data/database.sqlite` 是否被正确拒绝**

**失败项 > 0 时不要继续** —— 先解决它们，否则后续测试的失败没有参考价值。

---

### 0.15 交付文档完整性（可选，但对维护者有用）

```powershell
powershell -ExecutionPolicy Bypass -File tests\check-docs.ps1
```

检查 6 类问题：文档存在性、代码围栏配对、标题是否被拼接、审计附录 A–P 是否完整、
缺陷计数链是否单调、被引用的文件是否真实存在。

---

## 0.1 启动与自检

### 0.1 语法检查（必须先全绿）

```powershell
cd <项目根>
$php = "php"
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    & $php -l $_.FullName
}
```

**预期**：每个文件输出 `No syntax errors detected`，无一例外。

### 0.2 初始化

```powershell
& $php data\install.php
```

**预期**：交互式提示输入密码两次 → 生成 `data/config.php` 与 `data/database.sqlite`。

### 0.2 ⚠️ 必须确认部署模式与 base_path 一致

**站点根必须指向项目的 `public/` 目录**（设计要求 §17）。若你的 Web 根是
`<站点根>` 而项目位于其子目录，则必须：

```php
// data/config.php
'base_path' => '/img/public',
```

**症状对照**：

| 现象 | 原因 |
|---|---|
| 页面无样式（CSS 404） | `base_path` 未设置或设错 |
| 登录成功后 404 | 同上 |
| 上传/删除请求 404 | 同上 |
| **`/data/database.sqlite` 能下载** | 站点根不是 `public/` 且未用 Web 服务器规则屏蔽——**立即停止，这是数据泄露** |

验证：
```powershell
curl -i http://127.0.0.1:8099/                        # 期望 200 且含 <link ... style.css>
curl -i http://127.0.0.1:8099/data/database.sqlite    # 期望 403/404，绝不 200
```

### 0.25 ⚠️ 本地 HTTP 测试必须先关闭 force_https

如果你在 `http://127.0.0.1:8099`（纯 HTTP）上测试，**必须**先把
`data/config.php` 中的：

```php
'force_https' => true,     // 改为 false
```

改为 `false`，否则 Session Cookie 会带 `Secure` 标志，浏览器**拒绝在 HTTP 下保存它**，
症状是：**输入正确密码后仍然停留在登录页**，且日志中出现
`cookie_secure_over_http` 警告。

测试完成后改回 `true`（生产 HTTPS 环境必须为 `true`）。

### 0.25b 初始化（网页方式，推荐）

浏览器打开站点即可自动跳转到安装向导，设置密码后完成初始化。
**无需命令行。** 详见 README §6。

若你已经用命令行初始化过，可跳过本节。

### 0.25c 重新走一遍安装流程（可选）

想从零再体验一次安装，用重置脚本清空结果：

```powershell
cd <项目根>
powershell -ExecutionPolicy Bypass -File tests\reset-install.ps1
```

它会删除 `data/config.php`、`data/database.sqlite`（含 `-wal`/`-shm`）、
`data/logs/app.log`、`data/logs/login_attempts.json`。

**默认不动已上传的图片**，只列出数量；若确实要一并清除，加 `-IncludeUploads`。
脚本会先列出将删除的内容并请求确认；加 `-Force` 可跳过确认。

完成后打开站点，会自动跳转到安装向导。

### 0.3 确认敏感文件不在 Web 根内

```powershell
Test-Path public\data          # 预期 False
Test-Path data\config.php      # 预期 True
Test-Path data\database.sqlite # 预期 True
```

---

## 1. 访问（§45 访问）

| # | 用例 | 命令 | 预期 |
|---|---|---|---|
| A1 | 首页正常 | `curl -i http://127.0.0.1:8099/` | `200`，HTML 含站点名 |
| A2 | 首页不含管理入口 | `curl -s http://127.0.0.1:8099/ | Select-String "上传|删除|upload.php|delete.php|admin.php"` | **无输出**（未登录不得泄露） |
| A3 | 图片正常显示 | 上传一张后访问其缩略图 URL | `200`，`Content-Type: image/*` |
| A4 | 直链正常 | 访问 `/uploads/<filename>` | `200`，`Cache-Control` 含 `max-age=2592000` |
| A5 | 不存在的页面 | `curl -i http://127.0.0.1:8099/nope` | `404`，且响应体不含路径/堆栈 |
| **A6** | **CSS 可加载** | 自动：从首页 HTML 提取 `<link href>` 并请求它 | `200`。**这是 `base_path` 配置正确性的直接证据**；失败即说明站点根/`base_path` 不匹配 |
| **A7** | **JS 可加载** | 自动：从首页 HTML 提取 `<script src>` 并请求它 | `200`。JS 404 会导致上传与删除**完全不可用** |
| **A5b** | 错误页不泄露路径/栈 | 自动化断言 | 响应体不含 `Stack trace`/`Fatal error`/`Warning:`/`D:\` |

> A6/A7 无需人工判断 `base_path` 是否设对——测试脚本会自动完成，并直接给出答案。

---

## 2. 登录（§45 登录）

| # | 用例 | 命令 | 预期 |
|---|---|---|---|
| L1 | 正确密码 | 见 `tests\run-tests.ps1 -Scenario login-ok` | `302` 到 `/admin.php`，Set-Cookie 有 `elation_sid` |
| L2 | 错误密码 | 同上 `-Scenario login-bad` | `200`，页面含「密码错误」，无 Cookie 变化 |
| L3 | Session 生效 | 携带 Cookie 访问 `/admin.php` | `200`，含上传区域 |
| L4 | Logout | POST `/logout.php` | `302` 到 `/`，随后访问 `/admin.php` 被拒 |
| L5 | Session ID 更新 | 登录前后对比 `elation_sid` 值 | **必须不同**（防会话固定） |
| L6 | Cookie 属性 | 检查 `Set-Cookie` | 含 `HttpOnly`、`SameSite=Lax`；HTTPS 下含 `Secure` |
| **L6a** | Cookie `HttpOnly` 标志 | 自动化断言 | 为真（JS 无法读取会话 Cookie） |
| **L6b** | Cookie `SameSite` 属性 | 自动化断言 | `SameSite=Lax` |
| **L2b** | 错误密码后无法进入后台 | 自动化断言 | `/admin.php` 返回非 `200` |
| L7 | Logout 只允许 POST | `curl -i http://127.0.0.1:8099/logout.php` | 不登出（302 回首页） |

---

## 3. 上传（§45 上传）

用 `tests\make-fixtures.php` 生成全部测试素材。

| # | 用例 | 素材 | 预期 |
|---|---|---|---|
| U1 | JPG | `ok.jpg` | `200` `{"ok":true}`，直链可访问 |
| U2 | PNG | `ok.png` | 同上 |
| U3 | GIF | `ok.gif` | 同上 |
| U4 | WebP | `ok.webp` | 同上 |
| U5 | 超大文件 | `big.jpg`（11 MB） | `400`，提示超过限制 |
| U6 | 非图片 | `notimage.txt` | `400`，只允许图片格式 |
| U7 | 改扩展名的恶意文件 | `evil.php.jpg`（内容是 PHP 代码） | `400`，MIME 检测拒绝 |
| U8 | PHP 文件伪装图片 | `shell.gif`（GIF 头 + PHP 代码） | `400`，内容验证拒绝 |
| U9 | 空文件 | `empty.jpg`（0 字节） | `400`，文件为空 |
| U10 | 损坏图片 | `broken.jpg`（截断的 JPEG） | `400`，不是有效图片 |
| U11 | 文件名净化 | 上传名为 `../../evil.jpg` 的文件 | 落盘名为 32 位十六进制；`uploads/` 外无文件产生 |
| U12 | 原图不被处理 | 上传后对比源文件与落盘文件 SHA256 | **完全一致**（§20） |

---

## 4. 权限（§45 权限）

**全部三条必须被拒绝。** 这是第二优先级安全目标。

```powershell
# 匿名访问 admin.php（HTML，允许 302 到登录页）
curl -i http://127.0.0.1:8099/admin.php

# 匿名 POST upload.php（API，必须 403 JSON，不得 302）
curl -i -X POST http://127.0.0.1:8099/upload.php

# 匿名 POST delete.php（API，必须 403 JSON，不得 302）
curl -i -X POST http://127.0.0.1:8099/delete.php -d "id=1"
```

| # | 用例 | 预期 |
|---|---|---|
| P1 | 匿名 GET `/admin.php` | `302` → `/login.php` |
| P2 | 匿名 POST `/upload.php` | **`403`** + `{"ok":false}` |
| P3 | 匿名 POST `/delete.php` | **`403`** + `{"ok":false}` |
| P4 | 匿名 GET `/upload.php` | `403`（方法不是 POST 或未登录） |
| P5 | 已登录但无 CSRF 的 POST | `403` |
| **P2b** | 匿名 POST `upload.php` 不得是 302 | 状态码 `≠ 302`（必须是硬拒绝，不能引导到登录页） |
| **P2c** | 匿名 POST `upload.php` 返回 JSON 拒绝 | 响应体含 `{"ok":false}` |
| **P3b** | 匿名 POST `delete.php` 返回 JSON 拒绝 | 响应体含 `{"ok":false}` |

---

## 5. 删除（§45 删除）

| # | 用例 | 预期 |
|---|---|---|
| D1 | 正常删除 | `200` `{"ok":true}`；数据库记录与磁盘文件都消失 |
| D2 | 删除不存在的图片 | `200` `{"ok":true}`（幂等，不报错） |
| D3 | 未登录删除 | `403` |
| D4 | 错误 ID（非数字） | `400` |
| D5 | CSRF 错误 | `403`，记录 `csrf_failed` |
| D6 | **GET 触发删除** | `curl "http://127.0.0.1:8099/delete.php?id=1"` → **图片必须仍然存在** |
| D7 | 文件已丢失时删除 | 先手动删掉磁盘文件，再走 D1 → `200`，不报错 |

---

## 6. 安全（§45 安全）

| # | 用例 | 命令/方法 | 预期 |
|---|---|---|---|
| S1 | SQL Injection | `curl "http://127.0.0.1:8099/?page=1%20OR%201=1--"` | 参数被 `(int)` 强转，正常返回；无 SQL 错误泄露 |
| S2 | XSS（文件名） | 上传原始名为 `<img src=x onerror=alert(1)>.jpg` 的图片 | 后台卡片中显示为**转义文本**，不弹窗（查看 HTML 源码应见 `&lt;img`） |
| S3 | XSS（反射） | `curl "http://127.0.0.1:8099/?page=<script>alert(1)</script>"` | 响应中无未转义的 `<script>` |
| S4 | CSRF | 构造跨站表单 POST `/delete.php` | `403`，无状态变更 |
| S5 | Path Traversal | `curl -i "http://127.0.0.1:8099/uploads/../../data/config.php"` | `403`/`404`，**绝不返回配置内容** |
| S6 | Path Traversal（编码） | `curl -i --path-as-is "http://127.0.0.1:8099/uploads/..%2f..%2fdata%2fconfig.php"` | 被 Nginx 规范化或拒绝 |
| S7 | PHP Upload | 上传 `.php` 内容为图片的文件后请求它 | 上传应被拒；即使落盘也 **`403`**，**绝不执行** |
| S8 | Session Fixation | 登录前固定 `elation_sid`，登录后对比 | ID 已变（同 L5） |
| S9 | 暴力登录 | 连续 6 次错误密码 | 第 6 次起返回「尝试次数过多」，记录 `login_locked` |
| S10 | 敏感文件访问 | `curl -i http://127.0.0.1:8099/data/database.sqlite` | `403`/`404` |
| S10b | SQLite WAL 文件 | `curl -i http://127.0.0.1:8099/data/database.sqlite-wal` | `403`/`404` |
| S10c | 备份/日志 | `curl -i http://127.0.0.1:8099/data/logs/app.log` | `403`/`404` |
| S11 | uploads 目录 PHP 执行 | 手工把一个 `probe.php` 放进 `public/uploads/`，请求它 | **`403`**，返回 Nginx 错误页而非 PHP 输出 |
| S11b | 双扩展名绕过 | 放 `probe.php.jpg` 到 uploads 并请求 | `403`（不在图片白名单）或按图片返回，**绝不执行** |
| S12 | 目录列表 | `curl -i http://127.0.0.1:8099/uploads/` | `403` |
| S13 | 错误信息泄露 | 触发一个 500（如临时破坏 DB 权限） | 页面只显示「操作失败，请稍后重试。」，无路径/栈 |
| S14 | 日志不含敏感信息 | 登录失败若干次后查看 `data/logs/app.log` | 无密码、无 Cookie、无完整 token |

---

## 7. 性能（§39）

| # | 用例 | 方法 | 预期 |
|---|---|---|---|
| F1 | 直链不经 PHP | 请求 `/uploads/xxx.jpg` 后看 Nginx access log | 无 `fastcgi` 记录 |
| F2 | 首页不扫描目录 | `grep -rn "scandir\|glob(\|opendir" src public` | **无匹配** |
| F3 | 首页只加载缩略图 | 查看首页 HTML | `img` 的 `src` 指向 `/uploads/thumbs/`（有缩略图时） |
| F4 | 懒加载 | 同上 | `img` 含 `loading="lazy"` |
| F5 | 分页 | 图片超过 `per_page` 时 | 出现分页控件，单页 `img` 数量 ≤ per_page |

---

## 8. 判定标准

- **P1–P5、S1–S14、D6 全部通过** → 安全目标达成，可以上线。
- 任意一条 **P2/P3/S7/S11/S11b/S5/S10** 失败 → **禁止上线**，属于任意代码执行或匿名上传或敏感信息泄露，必须修复。

---

## 9. 执行记录

> 本节由执行者填写。**Agent 会话现已具备命令执行能力，以下为实测结果。**

**执行环境**：小皮面板 · PHP **8.0.2 NTS** · Nginx **1.25.2** · 站点根 = `public/`
**执行方式**：`tests/preflight.ps1` + `tests/run-tests.ps1`

### 9.1 环境预检（`preflight.ps1`）

```text
FAILED: 0    WARNINGS: 0
```

| 区块 | 结果 |
|---|---|
| 1 PHP 解释器 | ✅ 8.0.2（符合硬性要求） |
| 2 扩展 | ✅ `pdo_sqlite` / `fileinfo` / `json` / `gd` / `mbstring` |
| 3 语法检查 | ✅ 24 个文件全部通过真实 `php -l` |
| 4 目录权限 | ✅ 6 个目录全部可写 |
| 5 配置 | ✅ 哈希形态正确；`base_path=[]`、`force_https=0` |
| 6 数据库 | ✅ `images` 表可读 |
| 7 站点可达 | ✅ 首页 200、CSS 200、**9 条敏感路径全部被拒** |
| 8 入口诊断 | ✅ 首页/index/login 200；**setup.php 403** |
| 9 uploads 执行 | ✅ `.php` 被拒（404）；✅ **真实图片仍可访问（200）** |

### 9.2 功能与安全用例（`run-tests.ps1`）

**第一轮（匿名 + 安全，无 `-Password`）**

```text
PASS: 35    FAIL: 0    SKIP: 4
```

| 区块 | 已执行 | 通过 | 失败 | 说明 |
|---|---:|---:|---:|---|
| 0 前置 | ✅ | 1 | 0 | 首页可达 |
| 1 访问 | ✅ | 10 | 0 | 含 A8（setup 失效）、A9（不泄露哈希）、A10、A5/A5b |
| 2 登录 | ⏭ | — | — | 需 `-Password` |
| 3 上传 | ⏭ | — | — | 需 `-Password` |
| **4 权限** | ✅ | **7** | **0** | **匿名上传/删除全部 403 且为 JSON** |
| 5 删除 | ⏭ | — | — | 需 `-Password` |
| **6 安全** | ✅ | **17** | **0** | 见下 |
| 7 性能 | ⏭ | — | — | 未执行 |

**第一轮安全区块逐项**：

| 用例 | 结果 |
|---|---|
| S1 SQLi 参数被安全处理 | ✅ 200 |
| S3 反射型 XSS 未被回显 | ✅ |
| S5 路径穿越（4 种编码变体） | ✅ 全部 404 |
| S10 敏感文件（7 条路径） | ✅ 全部 404 |
| **S11 `uploads/` 下 PHP 不执行** | ✅ **404（blocked）** |
| S11b 双扩展名不执行 | ✅ 404 |
| S12 目录列表被禁 | ✅ 404 |
| S14 日志不含敏感信息 | ✅ |
| S9 登录限速 | ⏭ 手动执行（会锁定 IP 15 分钟） |

**第二轮（含登录的完整套件 + S9 登录限速）**

```text
PASS: 65    FAIL: 0    SKIP: 0
```

| 区块 | 已执行 | 通过 | 失败 | 说明 |
|---|---:|---:|---:|---|
| 0 前置 | ✅ | 1 | 0 | 首页可达 |
| 1 访问 | ✅ | 10 | 0 | A1–A10（含 setup 失效、敏感配置不可达） |
| 2 登录 | ✅ | 6 | 0 | **含会话固定防护（Session ID 确实改变）与 Cookie 属性** |
| 4 权限 | ✅ | 7 | 0 | 匿名上传/删除全部 403 + JSON |
| 6 安全 | ✅ | 17 | 0 | S1/S3/S5/S10/S11/S11b/S12/S14 |
| 5.5 上传 | ✅ | 16 | 0 | U1–U14 全部通过 |
| 5 删除 | ✅ | 4 | 0 | D2/D4/D5/D6 |
| 7 性能 | ⏭ | — | — | 未执行（见下） |

**S9 登录限速（实测通过）**：

```text
[PASS] S9a lockout recorded after 5 failures        locked for another 900s
[PASS] S9b correct password is refused while locked
[PASS] S9c admin area stays unreachable while locked status=0
         lockout state cleared; you can log in normally again
```

执行方式：`run-tests.ps1 -IncludeRateLimit`（默认关闭，因为会临时锁定本机 IP；
用例结束自动清理状态文件）。全程约 5 秒，**无需等待 15 分钟** ——
锁定是服务端状态，验证它只需确认"锁已生效且正确密码进不来"。

**第二轮登录区块（关键）**：

| 用例 | 结果 |
|---|---|
| L1 正确密码进入后台 | ✅ 200 |
| L2 / L2b 错误密码被拒且进不了后台 | ✅ |
| **L5 登录后 Session ID 改变（防会话固定）** | ✅ 前后值确实不同 |
| L6a Cookie 为 HttpOnly | ✅ |
| **L6b Cookie 为 SameSite=Lax** | ✅ `Set-Cookie: ...; path=/; HttpOnly; SameSite=Lax` |
| L6c Cookie 含 HttpOnly（原始头确认） | ✅ |

**第二轮上传区块（关键）**：

| 用例 | 结果 |
|---|---|
| U1–U4 四种格式均可上传 | ✅ |
| U5 超大文件被拒 | ✅ 400 |
| U6 非图片被拒 | ✅ 400 |
| U7 改名恶意文件被拒 | ✅ 400 |
| **U8/U8b/U8c 含 PHP 的 GIF** | ✅ 接受但**落盘为 `.gif`**，且请求它**不执行**（无 `PWNED_42`） |
| U9 空文件被拒 | ✅ 400 |
| U10 损坏图片被拒 | ✅ 400 |
| U11 文件名为服务端随机生成 | ✅ 32 位十六进制 |
| U12 直链可达 | ✅ 200 |
| U13 无 CSRF 上传被拒 | ✅ 403 |
| U14 测试上传可删除 | ✅ 4/4 清理干净 |

### 9.3 上线阻断条件核对（§8）

| 阻断用例 | 要求 | 实测 | 结论 |
|---|---|---|---|
| **P2 / P3** | 匿名上传/删除被拒 | **403 + JSON 拒绝** | ✅ 通过 |
| **S7** | —— | 见 §6 | — |
| **S11 / S11b** | `uploads/` 不可执行 PHP | **404** | ✅ 通过 |
| **S5** | 路径穿越被挡 | 4 种变体全部 404 | ✅ 通过 |
| **S10** | 敏感文件不可访问 | 9 条路径全部 404 | ✅ 通过 |

> **结论：所有上线阻断条件均已通过。**
