<?php
declare(strict_types=1);

/**
 * 图片元数据访问层。全部使用 PDO 预处理语句（§25）。
 * 列表一律来自数据库，不扫描 uploads 目录（§39）。
 */

/** 统计图片总数。 */
function images_count(): int
{
    $stmt = db()->query('SELECT COUNT(*) AS c FROM images');
    $row = $stmt->fetch();
    return (int)($row['c'] ?? 0);
}


/**
 * 排序键白名单。
 *
 * **安全要点**：ORDER BY 无法用占位符绑定，只能拼接 SQL 片段 ——
 * 因此绝不能把用户输入直接拼进去。这里用"请求值 -> 固定 SQL 片段"的映射，
 * 请求值只用于查表，永远不会进入 SQL。
 */
function images_sort_sql(string $sort): string
{
    $map = [
        'new'   => 'created_at DESC, id DESC',   // 最新优先（默认）
        'old'   => 'created_at ASC, id ASC',     // 最早优先
        'big'   => 'size DESC, id DESC',         // 体积最大
        'small' => 'size ASC, id ASC',           // 体积最小
        'name'  => 'original_name ASC, id ASC',  // 文件名
    ];
    return $map[$sort] ?? $map['new'];
}

/** 排序选项（供界面渲染下拉框；value 传给 images_sort_sql）。 */
function images_sort_options(): array
{
    return [
        'new'   => '最新上传',
        'old'   => '最早上传',
        'big'   => '文件最大',
        'small' => '文件最小',
        'name'  => '按文件名',
    ];
}

/**
 * 分页查询。
 * 返回 ['rows'=>array, 'total'=>int, 'page'=>int, 'pages'=>int]
 */
function images_page(int $page, int $perPage, string $sort = 'new'): array
{
    $perPage = max(1, min(120, $perPage));
    $total = images_count();
    $pages = max(1, (int)ceil($total / $perPage));

    if ($page < 1) { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        'SELECT id, filename, original_name, mime, size, width, height, sha256, thumb, created_at
         FROM images
         ORDER BY ' . images_sort_sql($sort) . '
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows'  => $stmt->fetchAll(),
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
    ];
}

/** 按 id 查询单张图片。 */
function image_find(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id, filename, original_name, mime, size, width, height, sha256, thumb, created_at
         FROM images WHERE id = :id LIMIT 1'
    );
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 插入一条图片记录，返回新 id。
 * $meta 必须已由上传链路校验过。
 */
function image_insert(array $meta): int
{
    $stmt = db()->prepare(
        'INSERT INTO images (filename, original_name, mime, size, width, height, sha256, thumb, created_at)
         VALUES (:filename, :original_name, :mime, :size, :width, :height, :sha256, :thumb, :created_at)'
    );
    $stmt->execute([
        ':filename'      => $meta['filename'],
        ':original_name' => $meta['original_name'],
        ':mime'          => $meta['mime'],
        ':size'          => (int)$meta['size'],
        ':width'         => (int)$meta['width'],
        ':height'        => (int)$meta['height'],
        ':sha256'        => (string)$meta['sha256'],
        ':thumb'         => (int)$meta['thumb'],
        ':created_at'    => $meta['created_at'],
    ]);
    return (int)db()->lastInsertId();
}

/** 删除数据库记录。文件删除由调用方负责。 */
function image_delete(int $id): bool
{
    if ($id < 1) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM images WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

/**
 * 站点统计：总数、总占用、缩略图数。
 *
 * 为什么用一次查询而不是三次：这些都是简单聚合，SQLite 走一遍表即可；
 * 分成三条语句在图片多时会明显更慢，且没有收益。
 */
function images_stats(): array
{
    $row = db()->query(
        'SELECT COUNT(*) AS c,
                COALESCE(SUM(size), 0) AS bytes,
                COALESCE(SUM(CASE WHEN thumb = 1 THEN 1 ELSE 0 END), 0) AS thumbs
         FROM images'
    )->fetch();

    $total = (int)($row['c'] ?? 0);
    $thumbs = (int)($row['thumbs'] ?? 0);

    return [
        'count'       => $total,
        'bytes'       => (int)($row['bytes'] ?? 0),
        'thumbs'      => $thumbs,
        'no_thumb'    => $total - $thumbs,
    ];
}

/**
 * 最近上传的若干条（用于后台仪表盘）。
 */
function images_recent(int $limit = 6): array
{
    $limit = max(1, min(24, $limit));
    $stmt = db()->prepare(
        'SELECT id, filename, original_name, mime, size, width, height, sha256, thumb, created_at
         FROM images ORDER BY created_at DESC, id DESC LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * 分页查询（可带搜索）。
 *
 * @param string $q 搜索关键词，匹配原始文件名。空字符串表示不过滤。
 *
 * 关于 LIKE 的通配符转义：
 *   用户输入的 % 和 _ 在 LIKE 里是通配符。若不转义，"a_b" 会匹配到 "axb"，
 *   搜索行为与用户直觉不符。这里统一转义，并显式声明 ESCAPE 字符。
 */
function images_search(string $q, int $page, int $perPage, string $sort = 'new'): array
{
    $perPage = max(1, min(120, $perPage));
    $q = trim($q);

    if ($q === '') {
        return images_page($page, $perPage, $sort);
    }

    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $countStmt = db()->prepare(
        "SELECT COUNT(*) AS c FROM images WHERE original_name LIKE :q ESCAPE '\\'"
    );
    $countStmt->bindValue(':q', $like, PDO::PARAM_STR);
    $countStmt->execute();
    $total = (int)($countStmt->fetch()['c'] ?? 0);

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page < 1) { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    // ORDER BY 只能是白名单里的固定片段（见 images_sort_sql）
    $orderBy = images_sort_sql($sort);
    $stmt = db()->prepare(
        "SELECT id, filename, original_name, mime, size, width, height, sha256, thumb, created_at
         FROM images
         WHERE original_name LIKE :q ESCAPE '\\'
         ORDER BY " . $orderBy . "
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':q', $like, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows'  => $stmt->fetchAll(),
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
        'query' => $q,
        'sort'  => $sort,
    ];
}

/**
 * 批量按 id 取回记录（用于批量删除前确认）。
 * 返回以 id 为键的数组。
 */
function images_find_many(array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) { $clean[$id] = $id; }
    }
    if ($clean === []) {
        return [];
    }
    // 上限保护：一次最多处理 500 条，避免超长 IN 子句
    $clean = array_slice($clean, 0, 500, true);

    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $stmt = db()->prepare(
        'SELECT id, filename, original_name, mime, size, width, height, sha256, thumb, created_at
         FROM images WHERE id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_values($clean));

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

/**
 * 一致性自检：找出"有文件无记录"与"有记录无文件"的图片。
 *
 * 为什么需要：
 *   - 删除时若文件删除失败但记录已删，会留下**孤儿文件**（占用磁盘且无法在界面看到）
 *   - 若文件因误删/磁盘故障丢失，数据库记录仍在，首页会出现**死链卡片**
 *   这两种情况都不会报错，只会默默存在。
 *
 * 实现要点（性能）：
 *   **只扫描一次目录、只查询一次数据库**，然后在内存里求差集。
 *   若对每条记录都做一次 file_exists()，一万张图就是一万次 stat() ——
 *   在机械盘或网络盘上会非常慢。这里用已取回的目录列表做集合运算，避免该开销。
 *
 * @param int $limit 每类最多返回多少条（防止结果过大）
 * @return array{files:int,records:int,orphan_files:array,missing_files:array,orphan_count:int,missing_count:int}
 */
function images_consistency_check(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));

    // 1) 目录里的图片文件（只取我们自己的命名规则）
    $onDisk = [];
    $items = @scandir(UPLOAD_DIR);
    if (is_array($items)) {
        foreach ($items as $item) {
            if (preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/i', $item) === 1) {
                $onDisk[$item] = true;
            }
        }
    }

    // 2) 数据库里的文件名
    $inDb = [];
    $stmt = db()->query('SELECT filename FROM images');
    foreach ($stmt->fetchAll() as $row) {
        $f = (string)$row['filename'];
        if ($f !== '') { $inDb[$f] = true; }
    }

    // 3) 求差集
    $orphan = [];      // 有文件、无记录
    foreach ($onDisk as $name => $_) {
        if (!isset($inDb[$name])) {
            $orphan[] = $name;
            if (count($orphan) >= $limit) { break; }
        }
    }

    $missing = [];     // 有记录、无文件
    foreach ($inDb as $name => $_) {
        if (!isset($onDisk[$name])) {
            $missing[] = $name;
            if (count($missing) >= $limit) { break; }
        }
    }

    sort($orphan);
    sort($missing);

    return [
        'files'         => count($onDisk),
        'records'       => count($inDb),
        'orphan_files'  => $orphan,
        'missing_files' => $missing,
        'orphan_count'  => count($orphan),
        'missing_count' => count($missing),
    ];
}

/**
 * 删除孤儿文件（有文件、无记录）。
 * 只接受调用方从 images_consistency_check() 取到的文件名，
 * 且再次校验命名规则 —— 不信任任何外部传入的路径。
 *
 * @return array{ok:bool,deleted:int,freed:int,failed:int}
 */
function images_purge_orphans(array $names, int $max = 500): array
{
    $deleted = 0;
    $failed  = 0;
    $freed   = 0;
    $max     = max(1, min(2000, $max));

    foreach ($names as $name) {
        if ($deleted + $failed >= $max) { break; }
        $name = basename((string)$name);
        // 二次校验：即便上游被改动，这里也再确认一次命名规则
        if (preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/i', $name) !== 1) {
            $failed++;
            continue;
        }
        $path = UPLOAD_DIR . '/' . $name;
        if (!is_file($path)) {
            $failed++;
            continue;
        }
        $size = (int)@filesize($path);
        if (@unlink($path)) {
            // 缩略图一并删除（它同样不该存在）
            @unlink(THUMB_DIR . '/' . $name);
            $deleted++;
            $freed += $size;
        } else {
            $failed++;
        }
    }

    return ['ok' => $failed === 0, 'deleted' => $deleted, 'freed' => $freed, 'failed' => $failed];
}

/**
 * 为当前页的卡片计算"需要注意"的标记。
 *
 * 两类标记：
 *   1. 文件缺失 —— 数据库有记录但磁盘上没有文件，卡片点了打不开
 *   2. 原始文件名重复 —— 随机文件名不会冲突，但原始名可能重名，
 *      列表里会出现两张看起来一样的图，难以分辨
 *
 * 性能：只对**当前页的这几十条**做 is_file()（实测每页约 0.034 ms），
 * 不做整目录扫描 —— 后者会随图库增长而变慢，而这里只关心显示的这几张。
 * 重名检测只查当前页涉及的名字，用 IN (...) 限定，避免全表分组。
 *
 * @param array $rows images_page()/images_search() 返回的行
 * @return array{missing:array<string,bool>,dupes:array<string,int>}
 */
function images_page_flags(array $rows): array
{
    $missing = [];
    $names   = [];

    foreach ($rows as $row) {
        $safe = safe_filename((string)$row['filename']);
        if ($safe === null) {
            continue;
        }
        // 文件是否还在磁盘上
        if (!is_file(UPLOAD_DIR . '/' . $safe)) {
            $missing[$safe] = true;
        }
        $orig = (string)$row['original_name'];
        if ($orig !== '') { $names[$orig] = true; }
    }

    // 重名统计：只针对本页出现的原始名，加 IN 限定避免全表分组
    $dupes = [];
    if ($names !== []) {
        $list = array_keys($names);
        $ph = implode(',', array_fill(0, count($list), '?'));
        $stmt = db()->prepare(
            'SELECT original_name, COUNT(*) AS c
             FROM images
             WHERE original_name IN (' . $ph . ')
             GROUP BY original_name
             HAVING COUNT(*) > 1'
        );
        $stmt->execute($list);
        foreach ($stmt->fetchAll() as $r) {
            $dupes[(string)$r['original_name']] = (int)$r['c'];
        }
    }

    return ['missing' => $missing, 'dupes' => $dupes];
}

/**
 * 存储概况：图库占用 + 所在磁盘剩余。
 *
 * 为什么放在这里而不是只留在预检脚本里：
 *   预检要手动跑命令才能看到。而"磁盘还剩多少"是随时都该能瞥一眼的信息 ——
 *   磁盘写满时不只是上传失败，**PHP 的 session 文件也写不进去**，
 *   可能表现为全站异常，而报错信息往往指向别处。
 *
 * 阈值与 tests/preflight.ps1 的 7b 节保持一致（2 GB 警告 / 500 MB 危险），
 * 避免两处给出不同结论。
 *
 * @return array{library_bytes:int,files:int,disk_free:?int,disk_total:?int,level:string}
 *         level 为 ok / low / critical / unknown
 */
function storage_summary(): array
{
    $stats = images_stats();
    $files = 0;
    foreach ((array)@glob(UPLOAD_DIR . '/*') as $f) {
        if (is_file((string)$f)) { $files++; }
    }

    // 从 APP_ROOT 推导盘符（Windows）或直接查根目录（其他平台）
    $free  = null;
    $total = null;
    $root  = str_replace('\\', '/', APP_ROOT);
    $path  = '/';
    if (preg_match('#^([A-Za-z]:)#', $root, $m) === 1) {
        $path = $m[1];          // Windows：C: / D: 这样查
    }
    $f = @disk_free_space($path);
    $t = @disk_total_space($path);
    if ($f !== false && $t !== false) {
        $free  = (int)$f;
        $total = (int)$t;
    }

    // level 用**绝对剩余空间**判断：它回答的是"还能不能写"。
    // 对一个图床来说，剩 17 GB 就是够用，哪怕磁盘已经用了 97%。
    $level = 'unknown';
    $usedPct = null;
    if ($free !== null && $total !== null && $total > 0) {
        if ($free < 500 * 1048576) {
            $level = 'critical';       // 与预检一致：低于 500 MB 判为危险
        } elseif ($free < 2 * 1073741824) {
            $level = 'low';            // 低于 2 GB 警告
        } else {
            $level = 'ok';
        }
        $usedPct = (int)round(($total - $free) / $total * 100);
    }

    // 已用百分比单独提示：它说明**这台机器上有别的东西在吃磁盘**。
    // 与 level 是两个不同的问题，不能混为一谈 ——
    // 剩 17 GB 对本站够用，但那 17 GB 迟早也会被吃掉。
    $nearlyFull = ($usedPct !== null && $usedPct >= 90 && $level === 'ok');

    return [
        'library_bytes' => (int)$stats['bytes'],
        'files'         => $files,
        'disk_free'     => $free,
        'disk_total'    => $total,
        'used_pct'      => $usedPct,
        'nearly_full'   => $nearlyFull,
        'level'         => $level,
    ];
}
