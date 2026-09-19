/* Elation Image — 主题切换（跟随系统 / 始终浅色 / 始终深色）
   无第三方库。

   为什么单独一个文件、且在 <head> 里【同步】引入：
     主题必须在**首次绘制之前**应用到 <html> 上，否则每次打开页面都会先闪一下
     浅色再跳成深色。异步（defer/async）脚本做不到这一点。

   为什么不写成内联 <script>：
     本站 CSP 是 script-src 'self'，不允许内联脚本。独立文件既满足 CSP，
     又能被浏览器缓存。

   工作方式：
     1. 读取 localStorage 里保存的偏好（'auto' | 'light' | 'dark'）
     2. 把 'auto' **解析成** 'light' 或 'dark'，并写入 <html data-theme="...">
        —— 这样 CSS 里深色变量只需写一份（[data-theme="dark"]），
           不必再和媒体查询各写一遍
     3. 当偏好是 'auto' 时，监听系统主题变化并跟随更新
*/
(function () {
    'use strict';

    var KEY = 'elation-theme';
    var root = document.documentElement;

    function stored() {
        try {
            var v = window.localStorage.getItem(KEY);
            return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto';
        } catch (e) {
            // 隐私模式等场景下 localStorage 可能不可用：退回跟随系统
            return 'auto';
        }
    }

    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function resolve(pref) {
        if (pref === 'auto') { return systemPrefersDark() ? 'dark' : 'light'; }
        return pref;
    }

    function apply(pref) {
        var theme = resolve(pref);
        root.setAttribute('data-theme', theme);
        // 记下"用户选的偏好"而不是解析结果，供界面读取
        root.setAttribute('data-theme-pref', pref);
        // 让浏览器原生控件（滚动条、表单）也跟着变
        root.style.colorScheme = theme;
    }

    // 首次应用：必须在绘制前完成
    apply(stored());

    // 系统主题变化时，仅在"跟随系统"模式下跟随
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onChange = function () {
            if (stored() === 'auto') { apply('auto'); }
        };
        if (mq.addEventListener) { mq.addEventListener('change', onChange); }
        else if (mq.addListener) { mq.addListener(onChange); }   // 旧版 Safari
    }

    // 对外暴露，供切换按钮使用
    window.ElationTheme = {
        get: stored,
        set: function (pref) {
            try { window.localStorage.setItem(KEY, pref); } catch (e) { /* 忽略 */ }
            apply(pref);
        },
        resolved: function () { return resolve(stored()); },
        /** 在 auto -> light -> dark -> auto 之间循环 */
        cycle: function () {
            var order = ['auto', 'light', 'dark'];
            var cur = stored();
            var next = order[(order.indexOf(cur) + 1) % order.length];
            window.ElationTheme.set(next);
            return next;
        }
    };
})();
