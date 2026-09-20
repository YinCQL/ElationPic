<?php
declare(strict_types=1);

/**
 * 站点配置的读取与安全改写。
 *
 * 为什么单独一个文件：
 *   `public/setup.php`（首次安装）与 `public/settings.php`（后续修改）
 *   都需要**把配置写回 data/config.php**。这段逻辑必须只有一份 ——
 *   写坏配置会让整个站点无法启动，不能出现两个实现各自演化。
 *
 * 安全要点：
 *   1. **键白名单**：只接受下面 `settings_allowed_keys()` 列出的键。
 *      配置文件是 PHP，若允许任意键，就等于给了任意代码写入能力。
 *   2. **值类型收敛**：每个键都按其类型强制转换，绝不用 var_export 原样导出
 *      未校验的用户输入。
 *   3. **原子写入**：先写临时文件再 rename。中途失败时旧配置仍然完好，
 *      不会出现"写了一半、站点起不来"的状态。
 *   4. **保留入口守卫**：写入的内容始终包含"被直接请求则 404"的那段，
 *      否则 data/ 一旦可被 Web 访问就会泄露密码哈希。
 */

/** 允许通过界面修改的配置键及其类型。 */
function settings_allowed_keys(): array
{
    return [
        'site_name'          => 'string',
        'site_description'   => 'string',
        'site_url'           => 'url',
        'base_path'          => 'string',
        'per_page'           => 'int',
        'max_file_bytes'     => 'int',
        'thumb_max_edge'     => 'int',
        'strip_metadata'     => 'bool',
        'timezone'           => 'string',
        'force_https'        => 'bool',
        'login_max_attempts' => 'int',
        'login_window_secs'  => 'int',
        'login_lockout_secs' => 'int',
        'log_level'          => 'string',
    ];
}

/** 配置文件路径。 */
function settings_config_file(): string
{
    return DATA_DIR . '/config.php';
}

/**
 * 读取现有配置（含密码哈希）。
 * 失败时返回空数组 —— 调用方应自行判断。
 */
function settings_load(): array
{
    $f = settings_config_file();
    if (!is_file($f)) {
        return [];
    }
    $c = @include $f;
    return is_array($c) ? $c : [];
}

/**
 * 按类型收敛一个值。
 * 未知键或类型不符时返回 null，由调用方决定是忽略还是报错。
 */
function settings_coerce(string $key, $value)
{
    $types = settings_allowed_keys();
    if (!isset($types[$key])) {
        return null;
    }
    switch ($types[$key]) {
        case 'int':
            if (is_string($value) && $value !== '' && !ctype_digit(ltrim($value, '-'))) {
                return null;
            }
            return (int)$value;

        case 'bool':
            // 表单里通常是 "1"/"0"、true/false 或 "on"
            if (is_bool($value)) {
                return $value;
            }
            $s = strtolower(trim((string)$value));
            return in_array($s, ['1', 'true', 'on', 'yes'], true);

        case 'url':
            $s = trim((string)$value);
            if ($s === '') {
                return '';   // 留空表示自动推导
            }
            // 只接受 http/https 的绝对地址，且必须能解析出主机名
            if (preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#i', $s) !== 1) {
                return null;
            }
            return rtrim($s, '/');

        case 'string':
        default:
            $s = (string)$value;
            // 去掉控制字符，防止把换行等注入到配置文件里
            $s = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/u', '', $s);
            return is_string($s) ? $s : '';
    }
}

/** 各键的取值范围（仅对 int 生效）。 */
function settings_ranges(): array
{
    return [
        'per_page'           => [1, 120],
        'max_file_bytes'     => [1024, 104857600],       // 1 KB ~ 100 MB
        'thumb_max_edge'     => [0, 4000],
        'login_max_attempts' => [1, 100],
        'login_window_secs'  => [60, 86400],
        'login_lockout_secs' => [60, 86400],
    ];
}

/**
 * 校验并规范化整份配置。
 * 返回 ['ok'=>bool, 'cfg'=>array, 'errors'=>string[]]
 */
function settings_validate(array $input, array $current): array
{
    $errors = [];
    $out    = $current;
    $ranges = settings_ranges();

    // 复选框在未勾选时浏览器**根本不会提交该字段**。
    // 若沿用"缺失即跳过"的逻辑，用户就永远无法把它关掉。
    // 因此这些键在缺失时按 false 处理。
    $checkboxes = ['strip_metadata'];

    foreach (settings_allowed_keys() as $key => $type) {
        $isCheckbox = in_array($key, $checkboxes, true);
        if (!array_key_exists($key, $input)) {
            if ($isCheckbox) {
                $out[$key] = false;
            }
            continue;
        }
        $v = settings_coerce($key, $input[$key]);
        if ($v === null) {
            $errors[] = $key . ' 的取值不合法。';
            continue;
        }
        if ($type === 'int' && isset($ranges[$key])) {
            [$min, $max] = $ranges[$key];
            if ($v < $min || $v > $max) {
                $errors[] = $key . ' 需在 ' . $min . ' ~ ' . $max . ' 之间。';
                continue;
            }
        }
        $out[$key] = $v;
    }

    if (($out['site_name'] ?? '') === '') {
        $out['site_name'] = 'ElationPic';
    }

    return ['ok' => $errors === [], 'cfg' => $out, 'errors' => $errors];
}

/**
 * 原子地把配置写回 data/config.php。
 *
 * 先写同目录下的临时文件，成功后再 rename 覆盖 ——
 * rename 在同一文件系统上是原子的，因此不存在"写坏一半"的中间态。
 *
 * @param array $cfg 完整配置数组（必须已通过 settings_validate）
 * @return bool 是否写入成功
 */
function settings_save(array $cfg): bool
{
    $file = settings_config_file();

    // 只导出白名单内的键，其余键（如 admin_password_hash）另行处理：
    // 这里把传入的数组完整导出，但调用方必须保证其来源可信。
    $export = "<?php\ndeclare(strict_types=1);\n\n"
            . "// 由安装向导 / 后台设置页生成。请勿提交到版本库。\n\n"
            . "// 纵深防御：被当作入口脚本直接请求时返回 404。\n"
            . "if (isset(\$_SERVER['SCRIPT_FILENAME']) && is_string(\$_SERVER['SCRIPT_FILENAME'])\n"
            . "    && realpath(\$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {\n"
            . "    http_response_code(404);\n"
            . "    header('Content-Type: text/plain; charset=utf-8');\n"
            . "    echo \"Not Found\\n\";\n"
            . "    exit;\n"
            . "}\n\n"
            . "return " . var_export($cfg, true) . ";\n";

    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $export, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0660);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    // 让同一请求内后续的 cfg() 读到新值
    if (function_exists('cfg_reload')) {
        cfg_reload();
    }
    return true;
}
