<?php
declare(strict_types=1);

/**
 * 配置模板。复制为 config.php 后修改。
 * 生成密码哈希： php data/install.php
 *
 * 本文件位于 Web 根之外，正常情况下无法被 HTTP 访问。
 */

// 纵深防御：若本文件被当作入口脚本直接请求（站点根被误设为项目根），
// 直接返回 404，而不是安静地返回配置结构。
// include 时 __FILE__ 与 SCRIPT_FILENAME 不同，因此不影响正常加载。
if (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

return [
    // ★ 必填：管理员密码哈希（用 password_hash 生成，禁止明文）
    'admin_password_hash' => 'REPLACE_ME',

    // 站点展示信息
    'site_name'           => 'ElationPic',
    'site_description'    => 'A lightweight personal image hosting system.',

    // 底栏右下角的文字。
    //
    // 可放备案号、版权声明或联系方式。按纯文本输出（不解析 HTML）。
    // 留空时显示默认文案 "Powered by ElationPic"。
    'footer_note'         => '',

    // 首页是否公开。
    //
    //   true （默认）-> 任何人都能浏览图片列表
    //   false        -> 访客只看到「本站未公开」提示页
    //
    // ★ 关闭后**已发出的直链仍然有效**。图片由 Web 服务器直接返回，
    //   不经过 PHP，因此这个开关只影响列表展示，不影响外链。
    //   这适合"图片要贴到论坛，但不想让人翻整个图库"的场景。
    //
    // 已登录的管理员不受影响，照常浏览。
    'public_gallery'      => true,

    // 展示用时区（数据库恒存 UTC）
    'timezone'            => 'Asia/Shanghai',

    // 可选：站点完整地址，用于生成"复制直链"的绝对 URL。
    //   - 留空（推荐）：自动按当前访问的主机推导（会做严格校验）
    //   - 填写：如 'https://img.example.com'，则直链固定用该域名，
    //           适合绑定了固定域名的生产环境，或需要生成与访问地址不同的直链时
    'site_url'            => '',

    // 站点基础路径。两种部署模式：
    //   模式 A（推荐，符合 §17）：站点根 = 项目下的 public/ 目录
    //           -> base_path = ''        （留空）
    //   模式 B：站点根是某个上级目录，项目放在它的子目录里
    //           例如站点根 = /var/www，项目在 /var/www/elationpic，
    //           且站点根仍指向 /var/www
    //           -> base_path = '/elationpic/public'
    //   设置错误会导致 CSS 丢失、登录跳转 404、上传失败。
    'base_path'           => '',

    // 首页/后台每页图片数
    'per_page'            => 20,

    // 单文件上限（字节）。10 MB = 10485760，20 MB = 20971520
    // 修改后必须同步调整 deploy/php.ini 的 upload_max_filesize 与 post_max_size
    'max_file_bytes'      => 10485760,

    // 缩略图最长边（像素）。设为 0 关闭缩略图（首页回退原图）
    'thumb_max_edge'      => 480,

    // 上传时是否移除照片中的隐私元数据（EXIF / GPS / XMP / IPTC）。
    //
    //   ★ true （默认，推荐）-> 移除 GPS 坐标、拍摄时间、设备型号等
    //   ★ false              -> 原样保留全部元数据
    //
    // 为什么默认开启：手机拍摄的照片普遍带 GPS 坐标，而图床的直链是公开的，
    // 等于把**拍摄地点**一起公开了。这是一个不易察觉、后果却很实在的隐私问题。
    //
    // 实现方式：只删除元数据段，**不重新压缩图片**，因此画面像素逐字节不变，
    // 仍然符合"原图不做处理"的承诺（仅针对 JPEG / PNG / WebP；GIF 不受影响）。
    // 颜色配置（ICC）会被保留，否则会导致颜色失真。
    'strip_metadata'      => true,

    // 是否要求 HTTPS（令 Session Cookie 带 Secure 标志）。
    //
    //   ★ 生产环境（https://）      -> true   （必须）
    //   ★ 本地 HTTP 调试（http://） -> false  （否则浏览器不保存 Cookie，
    //                                          表现为「输对密码却仍停在登录页」）
    //
    // 如果你在纯 HTTP 环境下跑测试，
    // 必须先把它改为 false，测试完再改回 true。
    'force_https'         => true,

    // 登录限速
    'login_max_attempts'  => 5,
    'login_window_secs'   => 900,
    'login_lockout_secs'  => 900,

    // 日志级别：debug | info | warning | error
    'log_level'           => 'info',
];
