# 部署说明

本文说明 ElationPic 的运行环境要求与服务器配置要点。
适用于任意 Web 服务器，不针对特定面板或操作系统。

---

## 1. 环境要求

| 项目 | 要求 |
|---|---|
| PHP | **8.0.2 或更高**（8.0.x 为开发与测试目标版本） |
| 扩展 | `pdo_sqlite`、`gd`、`fileinfo`、`json`、`zip` |
| 数据库 | **无需安装** —— 使用 PHP 自带的 SQLite |
| Web 服务器 | Nginx、Apache 或其他均可 |

确认扩展是否齐全：

```bash
php -m | grep -E 'pdo_sqlite|gd|fileinfo|json|zip'
```

`tests/preflight.ps1` 会逐项检查上述要求，以及目录权限、敏感文件可达性、
磁盘剩余空间、`uploads` 是否可执行 PHP。

---

## 2. 部署方式

**把网站根目录指向项目下的 `public/`**，不是项目根。

```text
项目位置    /srv/elationpic
网站根      /srv/elationpic/public
```

这样 `data/`（数据库、配置、日志、会话）就位于 Web 根之外，
**即使服务器配置写错，HTTP 也无法到达它们**。

若站点根必须是某个上级目录（例如 `/var/www`），
请把 `base_path` 配置为项目相对于站点根的路径（例如 `/elationpic/public`）。

---

## 3. 服务器配置要点

### 3.1 上传目录不得执行脚本（最重要）

`uploads/` 存放用户上传的文件，**必须确保其中的文件只会被当作静态文件返回**，
任何情况下都不交给 PHP 解释。

Nginx：

```nginx
# 放在通用的 location ~ \.php$ 之前
location ^~ /uploads/ {
    # 这里不要写 fastcgi_pass
}
```

现成片段见 `deploy/FIX-uploads-no-exec.conf`。

Apache：

```apache
<Directory "/srv/elationpic/public/uploads">
    php_flag engine off
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps
</Directory>
```

**部署后务必验证**：向 `uploads/` 放一个 `.php` 文件并访问它，
应当返回 404 或下载，**绝不能返回脚本执行结果**。
可用 `tests/check-uploads-exec.ps1` 自动检查。

### 3.2 禁止访问 data 目录

`data/` 应当在 Web 根之外，通常无需额外规则。若无法做到，必须显式拒绝：

```nginx
location ~ ^/(data|backup)/ { deny all; return 404; }
location ~ \.(sqlite|sqlite-wal|sqlite-shm)$ { deny all; return 404; }
```

### 3.3 目录可写

PHP 进程需要对 `data/` 有写权限（数据库、配置、日志、会话），
`public/uploads/` 同样需要可写。

程序会尝试自动创建缺失目录；若失败，请手工创建并设置属主。

---

## 4. PHP 配置建议

`deploy/php.ini` 提供了生产环境建议配置，包括：

- 关闭 `display_errors`，错误写入日志
- 把 `error_log`、`session.save_path`、`upload_tmp_dir` 指向 `data/` 下（Web 根之外）
- 关闭危险函数（`exec`、`shell_exec`、`system` 等）
- 设置上传大小上限

请按自己的路径修改后再使用。

---

## 5. 性能建议

**开启 gzip 压缩。** CSS 与 JS 合计约 74 KB，压缩后约 24 KB：

```nginx
gzip on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_vary on;
gzip_types text/plain text/css text/javascript
           application/javascript application/json
           application/xml image/svg+xml;
```

**给静态资源设置长缓存。** 页面中的 CSS/JS 地址都带版本号，
文件变化时版本号随之变化，因此可以放心设很长时间：

```nginx
location ^~ /assets/ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}
```

两项合计可让首屏传输体积从约 91 KB 降到约 28 KB，
且重复访问时静态资源几乎不产生请求。

---

## 6. 部署后检查清单

| # | 检查项 | 怎么确认 |
|---|---|---|
| 1 | 网站根指向 `public/` | 打开站点能看到首页或安装向导 |
| 2 | `uploads/` 不执行 PHP | 放一个 `.php` 进去，访问应返回 404 或下载 |
| 3 | `data/` 无法通过 HTTP 访问 | 访问 `/../data/config.php` 应返回 403 或 404 |
| 4 | `data/` 可写 | 能完成安装向导 |
| 5 | 已设置管理员密码 | 安装向导强制要求 |

第 2 项最容易被忽略，也最要紧。

---

## 7. 升级与迁移

**升级**：替换 `src/` 与 `public/` 下的代码即可。
`data/` 与 `public/uploads/` 是数据，不要覆盖。

**迁移到新服务器**：

1. 在新服务器部署代码
2. 用后台「备份」页导出完整备份包（含数据库、配置、原图）
3. 在新服务器上导入该备份包

或者手工复制 `data/config.php`、`data/database.sqlite` 与 `public/uploads/`。

> 注意：WAL 模式下直接复制 `database.sqlite` 可能丢失最近的事务。
> 请使用内置的备份功能（`VACUUM INTO`）而不是文件复制。
