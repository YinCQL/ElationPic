<?php
declare(strict_types=1);

// 关闭 <main>。带侧边栏时还多一层 .admin-shell 容器需要收尾，
// 因此这里必须与 header.php 里的分支保持一致。
$__hadSidebar = !empty($isAdminPage) && !empty($loggedIn);
?>
</main>
<?php if ($__hadSidebar): ?>
</div><!-- /.admin-shell -->
<?php endif; ?>
<?php
$__fdesc = trim((string)cfg()['site_description']);

// 底栏右侧内容。
//
// 由「站点设置」里的 footer_note 控制，可以放备案号、版权声明、联系方式等。
// 留空时回落到一句默认文案 —— 底栏右侧空着会显得像没做完。
//
// 输出经过 e() 转义（纯文本，不解析 HTML）—— 让这个字段能写 HTML 等于
// 给后台开了一个注入点；填备案号这类纯文本需求用转义完全够。
$__fnote = trim((string)(cfg()['footer_note'] ?? ''));
if ($__fnote === '') {
    $__fnote = 'Powered by ElationPic';
}
?>
<footer class="site-footer">
    <div class="wrap footer-inner">
        <div class="footer-left">
            <span class="footer-name"><?= e((string)cfg()['site_name']) ?></span>
            <span class="sep">·</span>
            <span class="footer-version">v<?= e(APP_VERSION) ?></span>
            <?php if ($__fdesc !== ''): ?>
                <span class="sep">·</span>
                <span class="footer-desc"><?= e($__fdesc) ?></span>
            <?php endif; ?>
        </div>
        <div class="footer-right">
            <span class="footer-note"><?= e($__fnote) ?></span>
        </div>
    </div>
</footer>
<?php
// app.js 在页脚统一引入，而不是各页面各自加一行。
//
// 起因是一个真实缺陷：备份页的「删除」按钮依赖 app.js 里的点击处理器，
// 但 backup.php 从未引入该脚本 —— 按钮渲染正常、点击毫无反应、也不报错。
// 把引入点收敛到页脚后，"某个页面忘了加脚本"这类问题从结构上就不会再发生。
//
// defer 保证不阻塞解析；asset_url() 附带版本号，避免浏览器使用缓存的旧脚本。
?>
<?php
// 回到顶部按钮。
// 默认隐藏（hidden 属性），由 app.js 在滚动超过一屏后显示 ——
// 页面本来就不长时，出现一个"回到顶部"是多余的。
// 无 JS 时不显示，也不会占位（它带 hidden）。
?>
<button type="button" class="to-top" id="to-top" hidden
        title="回到顶部" aria-label="回到顶部">
    <span aria-hidden="true">↑</span>
</button>

<script src="<?= e(asset_url('/assets/app.js')) ?>" defer></script>
</body>
</html>