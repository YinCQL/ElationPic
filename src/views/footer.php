<?php
declare(strict_types=1);
?>
</main>
<?php $__fdesc = trim((string)cfg()['site_description']); ?>
<footer class="site-footer">
    <div class="wrap footer-inner">
        <div class="footer-left">
            <span class="footer-name"><?= e((string)cfg()['site_name']) ?></span>
            <?php if ($__fdesc !== ''): ?>
                <span class="sep">·</span>
                <span class="footer-desc"><?= e($__fdesc) ?></span>
            <?php endif; ?>
        </div>
        <div class="footer-right">
            <a href="<?= e(url('/')) ?>">首页</a>
            <?php if (!auth_is_logged_in()): ?>
                <span class="sep">·</span>
                <a href="<?= e(url('/login.php')) ?>">管理登录</a>
            <?php endif; ?>
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
