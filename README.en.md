# ElationPic

[中文](README.md) | **English**

> A simple, lightweight personal image host.

ElationPic is a **single-admin, zero-dependency** self-hosted image host. Upload an
image, get a direct link, paste it anywhere. No sign-ups, no multi-user, no social
features -- it does one thing and does it well.

- **Public gallery**: anyone can browse the wall, view originals, copy direct links
- **Single admin panel**: upload, delete, search, change settings
- **Zero third-party dependencies**: no Composer, no Node, no separate database server
- **Images are served directly by the web server**, never through PHP, so they load
  fast and put no load on PHP

---

## Scope

This is not an image platform that tries to do everything. It deliberately does
**not** do the following:

| Not included | Why |
|---|---|
| User registration / multi-user | A personal image host does not need it |
| Comments / likes / tags / albums | Conflicts with the "lightweight" goal |
| Image compression / transcoding / watermarks | Originals are stored exactly as uploaded |
| Image processing pipeline | Upload writes to disk; there are no queues or background jobs |

If you need any of the above, ElationPic is not for you.

---

## Features

**Browsing (public)**

- Responsive image wall for phone, tablet and desktop
- **Toggleable public gallery**: when off, visitors only see a "this site is not
  public" notice, but **previously shared direct links keep working** (images are
  served by the web server, not by the application)
- Sort by newest, oldest, largest/smallest file, or filename
- Click an image for a lightbox, or open the original in a new tab
- Light and dark themes: follows the system, or switch manually
- The first image loads eagerly, the rest lazily

**Admin (login required)**

- Drag-and-drop upload, or paste an image straight from the clipboard with Ctrl+V
- A **copyable direct link** is shown immediately after upload
- Delete a single image, bulk-delete selected, or select the whole page
- Search by original filename
- **Photo privacy metadata is stripped on upload** (EXIF / GPS / XMP / IPTC), on by default
- Site settings: title, description, canonical URL, per-page count, upload limit,
  thumbnail size, timezone, login rate limiting
- Change the admin password

**Backup and maintenance (login required)**

- **Export a complete backup**: database + site settings + original images in one zip
- **Import a backup**: replaces the database and settings, with an automatic rollback
  point created first
- **Rebuild thumbnails in bulk**: brings existing images up to date after changing the
  thumbnail size
- **File consistency check**: finds orphaned files (on disk, no database row) and
  broken records (row, no file), and can clean either up
- **Storage overview**: library size, free disk space, usage warnings

**Built for self-diagnosis**

- Front-end errors appear as an on-page banner (no devtools needed) with a copy button
- Problem cards are flagged directly in the image list (missing file, duplicate
  original filename)
- A command-line preflight script covers PHP version, extensions, syntax, directory
  permissions, sensitive file reachability, disk space and whether `uploads/` executes
  scripts

---

## Stack

| Item | Choice |
|---|---|
| Language | PHP **8.0.2+** |
| Database | **SQLite** (bundled with PHP; no database server to install) |
| Web server | Nginx (Apache and others work too) |
| Front end | Plain HTML / CSS / JavaScript -- no framework, no build step |
| Third-party dependencies | **None** (no Composer, no npm) |

**Required PHP extensions**: `pdo_sqlite`, `gd`, `fileinfo`, `json`, `zip`

> Why SQLite: one admin, low concurrency, small dataset. It uses less memory than
> MySQL, needs zero configuration, and exposes no database port or credentials to
> attack.

---

## Installation

### 1. Place the files

Put the project on your server, then **point the document root at `public/`**, not at
the project root.

```text
project location   /srv/elationpic
document root      /srv/elationpic/public
```

> This step matters. `data/` (database, config, logs) sits outside `public/`, so
> **even a misconfigured web server cannot expose it over HTTP**.

### 2. Open the site

Visiting your domain takes you to the **setup wizard**, which asks for:

- the admin password
- the site name and description
- the canonical site URL (used to build direct links; leave blank to detect it)
- per-page count, upload size limit, thumbnail max edge

When it finishes you can log in to the admin panel.

### 3. Confirm these three things

| # | Item | How to check |
|---|---|---|
| 1 | **`uploads/` must not execute PHP** | see below |
| 2 | **`data/` is unreachable over HTTP** | requesting `/../data/config.php` should return 403 or 404 |
| 3 | **`data/` is writable** | completing the setup wizard proves it |

Item 1 is the easiest to overlook and the most important. Your web server config
needs:

```nginx
# Upload directory: serve static files only, never execute a script
location ^~ /uploads/ {
    # note: do NOT put fastcgi_pass here
}
```

**And this block must come before the generic `location ~ \.php$`**, or it has no
effect. Ready-made snippets are in `deploy/`.

**Enabling gzip and static asset caching is also recommended** -- it cuts the initial
transfer from roughly 91 KB to roughly 28 KB. Snippets and step-by-step notes are in
`deploy/README-DEPLOY.md`.

---

## Configuration

Configuration is generated by the **setup wizard** and can be changed any time from
the **admin Settings page**; there is no need to edit files by hand.

It lives in `data/config.php`, which is **not tracked in the repository** (it contains
the admin password hash). The repo only ships the template
`data/config.sample.php`.

Available options:

| Option | Meaning |
|---|---|
| `site_name` / `site_description` | Site title and description |
| `site_url` | Canonical URL used to build direct links; blank means auto-detect from the request |
| `base_path` | Sub-path when the project is not at the domain root |
| `per_page` | Images per page |
| `max_file_bytes` | Per-file upload limit |
| `thumb_max_edge` | Thumbnail max edge (0 disables thumbnails) |
| `strip_metadata` | Strip photo privacy metadata on upload (EXIF / GPS / XMP / IPTC), on by default |
| `public_gallery` | Whether the gallery is public. Turning it off hides the listing but **not direct links** |
| `timezone` | Timezone |
| `force_https` | Force HTTPS redirects |
| `login_max_attempts` / `login_window_secs` / `login_lockout_secs` | Login rate limiting |
| `log_level` | Log level |

**Forgot your password**: delete `data/config.php` and revisit the site to return to
the setup wizard. This does not affect uploaded images or the database.

---

## Usage

1. Log in to the admin panel
2. Drag images into the upload area, or press Ctrl+V to paste a screenshot
3. Click "copy direct link" once the upload finishes
4. Paste the link anywhere

A direct link looks like `https://your-domain/uploads/filename.jpg` and is served by
the web server, bypassing PHP entirely.

**The gallery is public by default** -- anyone visiting your domain sees the image
wall. If you do not want that, add access restrictions at the web server level.
---

## Directory layout

```text
elationpic/
├── public/                    ★ document root (the only publicly reachable part)
│   ├── index.php              gallery (public browsing)
│   ├── setup.php              setup wizard
│   ├── login.php              login
│   ├── admin.php              admin panel
│   ├── settings.php           site settings (title, description, browsing)
│   ├── settings-advanced.php  security & advanced (upload, deployment, security)
│   ├── password.php           change password
│   ├── backup.php             backup and restore
│   ├── maintenance.php        maintenance tools (storage, thumbnails, consistency)
│   ├── export.php             export a backup bundle
│   ├── import.php             import a backup bundle
│   ├── upload.php             upload endpoint
│   ├── delete.php             delete one image
│   ├── delete_many.php        bulk delete
│   ├── rebuild_thumbs.php     rebuild thumbnails
│   ├── check_consistency.php  consistency check
│   ├── error.php              error page
│   ├── assets/                CSS / JS
│   │   └── css/               styles in three layers: base / polish / theme
│   └── uploads/               image directory (must not execute scripts)
│
├── src/                       application code (outside the document root)
│   ├── bootstrap.php          single entry bootstrap
│   ├── helpers.php            utility functions
│   ├── db.php                 database connection and schema
│   ├── auth.php               authentication and login throttling
│   ├── csrf.php               CSRF protection
│   ├── settings.php           settings read/write
│   ├── backup.php             backup bundle building and parsing
│   ├── images.php             image record reads and writes
│   ├── upload.php             upload validation chain
│   ├── thumbnails.php         GD thumbnails
│   └── views/                 page partials
│
├── data/                      ★ outside the web root, unreachable over HTTP
│   ├── config.sample.php      configuration template
│   ├── install.php            command-line initialisation (optional)
│   ├── backup.php             command-line backup
│   ├── logs/                  logs
│   ├── sessions/              sessions
│   └── tmp/                   temporary files
│
├── docs/                      design and development documentation
├── deploy/                    deployment config snippets and notes
├── tests/                     preflight and test scripts
├── tools/                     packaging script
└── backup/                    command-line backup output directory
```

---

## Maintenance

**Backup** (database + site settings + original images, in one zip):

```bash
php data/backup.php
```

You can also log in and download one from the "Backup" page.

> A backup bundle contains the admin password hash, which is equivalent to the keys
> to the site. Keep it safe. It is also worth syncing the image files incrementally
> to another location.

**Preflight check**:

```bash
powershell -ExecutionPolicy Bypass -File tests/preflight.ps1 -Base http://your-address
```

**Functional and security tests** (requires the admin password):

```bash
powershell -ExecutionPolicy Bypass -File tests/run-tests.ps1 -Base http://your-address -Password your-password
```

**Build a distribution package**:

```bash
powershell -ExecutionPolicy Bypass -File tools/make-package.ps1
```

---

## Caveats

- **The gallery is public by default**: anyone can browse it. Add web server
  restrictions if you need privacy.
- **Privacy metadata is stripped by default**: EXIF / GPS / XMP / IPTC are removed on
  upload. Phone photos commonly carry GPS coordinates, and a direct link is public --
  so leaving them in would publish where the photo was taken. That is why this is
  **on by default**. It can be turned off in the admin settings. The implementation
  removes metadata segments only and **does not re-encode the image**, so the pixels
  stay byte-for-byte identical.
- **Single admin**: there is no permission model; whoever holds the password holds
  full control.
- **The database is a single file**: back it up regularly. WAL is enabled, but copying
  `database.sqlite` directly can lose recent transactions -- use the built-in backup
  instead.
- **The test scripts target Windows PowerShell**, though the application itself is
  cross-platform.

---

## Roadmap

Planned but **not yet implemented**. Pull requests are welcome:

- [ ] Image tags and filtering
- [ ] Filter by upload date range
- [ ] Multiple thumbnail sizes
- [ ] Metadata stripping for GIF (currently JPEG / PNG / WebP only)

> This project is deliberately kept lightweight. Whether any of the above lands
> depends on actual need; none of it is guaranteed.

---

## Documentation

> The documents below are written in Chinese.

- `docs/DEVELOPMENT.md` -- development notes: database schema, file descriptions,
  deployment details, testing
- `docs/DESIGN.md` -- design document
- `docs/TEST-PLAN.md` -- test plan and verification checklist
- `deploy/README-DEPLOY.md` -- deployment and server configuration in detail

---

## License

[MIT](LICENSE)

This project contains no third-party code or dependencies; everything is an original
implementation.
