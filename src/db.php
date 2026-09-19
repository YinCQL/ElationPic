<?php
declare(strict_types=1);

/**
 * SQLite 连接与建表。首次调用自动创建数据库与表。
 * 全部查询使用 PDO 预处理（§25）。
 */

/** 单例 PDO 连接。 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    $isNew = !file_exists(DB_FILE);

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    db_migrate($pdo);

    if ($isNew) {
        @chmod(DB_FILE, 0660);
        log_event('info', 'db_created', ['file' => basename(DB_FILE)]);
    }

    return $pdo;
}

/** 建表。幂等。 */
function db_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS images (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            filename      TEXT    NOT NULL UNIQUE,
            original_name TEXT    NOT NULL,
            mime          TEXT    NOT NULL,
            size          INTEGER NOT NULL,
            width         INTEGER NOT NULL DEFAULT 0,
            height        INTEGER NOT NULL DEFAULT 0,
            sha256        TEXT    NOT NULL DEFAULT \'\',
            thumb         INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_images_filename   ON images(filename)');
    $pdo->exec('CREATE INDEX        IF NOT EXISTS idx_images_created_at ON images(created_at DESC)');
    // 说明：sha256 列保留（设计要求 §21 允许的 hash 字段，写入用于审计追溯），
    // 但不再建索引——它此前只服务于一个从未被调用的排重函数。
    // 本图床无排重需求（§1 功能清单中不存在），按 §44「不要为未来扩展过度设计」
    // 移除该索引，避免每次上传多维护一棵索引树。
}
