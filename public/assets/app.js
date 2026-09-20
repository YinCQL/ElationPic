/* ElationPic — 少量原生 JS（§33）。无第三方库。 */
(function () {
    'use strict';

    /* ---------- Toast ---------- */
    var toastEl = null;
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'toast';
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
    }

    /* ---------- 复制直链 ---------- */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.js-copy') : null;
        if (btn) {
            var url = btn.getAttribute('data-url') || '';
            if (!url) { return; }
            copyText(url).then(function () {
                toast('直链已复制');
            }).catch(function () {
                window.prompt('复制直链：', url);
            });
            return;
        }

        /* ---------- 删除（POST + CSRF） ---------- */
        var del = ev.target.closest ? ev.target.closest('.js-delete') : null;
        if (del) {
            var id = del.getAttribute('data-id') || '';
            if (!/^[0-9]+$/.test(id)) { return; }
            if (!window.confirm('确定删除这张图片？此操作不可撤销。')) { return; }
            del.disabled = true;
            postForm(siteUrl('/delete.php'), { id: id }, csrfFromDom()).then(function (res) {
                if (res.status === 401 || res.status === 403) {
                    toast('登录状态已失效，正在跳转…');
                    setTimeout(function () { window.location.reload(); }, 900);
                    return null;
                }
                return res.json();
            }).then(function (body) {
                if (body === null) { return; }
                if (body && body.ok) {
                    var card = del.closest('.card');
                    if (card && card.parentNode) { card.parentNode.removeChild(card); }
                    toast('已删除');
                } else {
                    del.disabled = false;
                    toast((body && body.error) || '删除失败，请稍后重试。');
                }
            }).catch(function () {
                del.disabled = false;
                toast('删除失败，请稍后重试。');
            });
        }
    });

    /* CSRF 从 <body data-csrf> 读取（无内联脚本，CSP 可保持严格） */
    function csrfFromDom() {
        return document.body.getAttribute('data-csrf') || '';
    }

    /* 站点基础路径，支持子目录部署（见 deploy/README-DEPLOY.md）。
       值由服务端渲染进 <body data-base>，绝不取自请求头，因此无注入风险。 */
    function basePath() {
        var b = document.body.getAttribute('data-base') || '';
        return b.replace(/\/+$/, '');
    }

    /* 把站点内路径转换为可请求的绝对路径 */
    function siteUrl(path) {
        if (!path || path.charAt(0) !== '/') { path = '/' + (path || ''); }
        return basePath() + path;
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            ok ? resolve() : reject();
        });
    }

    function postForm(url, fields, csrf) {
        var body = new URLSearchParams();
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        if (csrf) { body.append('csrf_token', csrf); }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf || ''
            },
            body: body.toString()
        });
    }

    /* ---------- 缩略图加载失败时回退原图 ----------
       场景：thumbs/ 下的文件缺失（手工清理、备份不完整、磁盘错误），
       但数据库仍标记 thumb=1。此时直接显示破图。
       用 error 事件回退到 data-full 指向的原图，且只回退一次，避免死循环。
       CSP 安全：不使用内联 onerror，全部通过 addEventListener。 */
    document.addEventListener('error', function (ev) {
        var img = ev.target;
        if (!img || img.tagName !== 'IMG' || !img.classList.contains('thumb')) { return; }
        var full = img.getAttribute('data-full');
        if (!full || img.dataset.fallbackDone === '1') { return; }
        img.dataset.fallbackDone = '1';
        img.removeAttribute('data-full');
        img.src = full;
    }, true);

    /* ---------- Lightbox（首页点击查看原图） ---------- */
    var lb = document.getElementById('lightbox');
    if (lb) {
        var lbImg = document.getElementById('lightbox-img');
        var closeBtn = lb.querySelector('.lightbox-close');
        document.addEventListener('click', function (ev) {
            var a = ev.target.closest ? ev.target.closest('.card-media') : null;
            if (!a) { return; }
            if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) { return; }
            ev.preventDefault();
            // 注意：**绝不能把 src 设为空字符串**。
            // 浏览器会把空的 src 解析为【当前页面地址】并尝试加载，
            // 必然失败并触发一次资源加载错误 —— 表现为首页上莫名其妙的报错。
            // 没有 src 属性时浏览器根本不会发起请求，因此这里改用 removeAttribute。
            if (lbImg) {
                var href = a.getAttribute('href') || '';
                if (href) { lbImg.src = href; } else { lbImg.removeAttribute('src'); }
            }
            lb.hidden = false;
        });
        function closeLb() {
            lb.hidden = true;
            // 同上：用 removeAttribute 而不是 src = ''，避免触发一次无效请求
            if (lbImg) { lbImg.removeAttribute('src'); }
        }
        lb.addEventListener('click', function (ev) {
            if (ev.target === lb || (closeBtn && ev.target === closeBtn)) { closeLb(); }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !lb.hidden) { closeLb(); }
        });
    }

    /* ---------- 后台上传 ----------
       注意：这里刻意**不使用提前 return**。
       早期版本写作 if (!dz || !input) { return; }，但那是从整个 IIFE 返回 ——
       于是任何**没有上传区**的页面（如 backup.php、settings.php）都会连带跳过
       后面所有代码，包括"删除备份文件"的点击处理器。
       症状是"按钮点了完全没反应"，而代码本身没有任何问题，极难排查。
       正确做法：只跳过**依赖这些元素的那部分装配**。 */
    var dz = document.getElementById('dropzone');
    var input = document.getElementById('file-input');
    var hasUploadUi = !!(dz && input);

    var progress = document.getElementById('progress');
    var bar = document.getElementById('progress-bar');
    var log = document.getElementById('upload-log');
    // CSRF token 来自 <body data-csrf>，避免内联脚本（保持 CSP script-src self）
    var csrf = document.body.getAttribute('data-csrf') || '';
    var busy = false;

    if (hasUploadUi) {
        dz.addEventListener('click', function () { if (!busy) { input.click(); } });
        dz.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); if (!busy) { input.click(); } }
        });
        input.addEventListener('change', function () {
            handleFiles(input.files);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (t) {
            dz.addEventListener(t, function (ev) { ev.preventDefault(); dz.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (t) {
            dz.addEventListener(t, function (ev) { ev.preventDefault(); dz.classList.remove('is-dragover'); });
        });
        dz.addEventListener('drop', function (ev) {
            if (ev.dataTransfer && ev.dataTransfer.files) { handleFiles(ev.dataTransfer.files); }
        });

        // 防止拖到页面其他位置时浏览器直接打开图片
        window.addEventListener('dragover', function (e) { e.preventDefault(); });
        window.addEventListener('drop', function (e) { e.preventDefault(); });
    }

    /**
     * 追加一条上传记录。
     * @param string text 文字
     * @param string kind 样式（ok/err）
     * @param string url  可选的直链；给了就附带一个「复制直链」按钮
     */
    function logLine(text, kind, url) {
        if (!log) { return; }
        var li = document.createElement('li');
        var span = document.createElement('span');
        span.textContent = text;
        li.appendChild(span);

        if (url) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm js-copy';
            btn.setAttribute('data-url', url);
            btn.textContent = '复制直链';
            li.appendChild(btn);
        }

        if (kind) { li.className = kind; }
        log.insertBefore(li, log.firstChild);
        while (log.children.length > 12) { log.removeChild(log.lastChild); }
    }

    function handleFiles(files) {
        if (busy || !files || !files.length) { return; }
        var list = Array.prototype.slice.call(files);
        busy = true;
        var idx = 0;

        function next() {
            if (idx >= list.length) {
                busy = false;
                if (progress) { progress.hidden = true; }
                if (bar) { bar.style.width = '0'; }
                return;
            }
            var f = list[idx++];
            uploadOne(f, function () { next(); });
        }
        next();
    }

    // 客户端预检：仅用于改善体验与节省带宽，绝不作为安全边界。
    // 服务端 src/upload.php 的 9 步校验链才是真正的防线（§19）。
    //
    // 限制值一律从 dropzone 的 data-* 读取（由 admin.php 用服务端配置渲染），
    // 避免在 JS 里写死常量后与 config.php 的 max_file_bytes 失配。
    // dz 可能为 null（例如 backup.php 没有上传区）—— 这里必须容错，
    // 否则整个脚本会在此处抛错，后续所有处理器（含删除备份）都不会挂上。
    var MAX_BYTES = parseInt(dz ? dz.getAttribute('data-max-bytes') : '', 10) || 10485760;
    var ALLOWED = ((dz ? dz.getAttribute('data-allowed-types') : '') || 'image/jpeg,image/png,image/gif,image/webp')
                    .split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    // 注意：点号必须转义，否则 "." 会匹配任意字符，使 evil.jpg.php 之类的名字通过预检
    var ALLOWED_EXT = /\.(jpe?g|png|gif|webp)$/i;

    function precheck(file) {
        if (file.size <= 0) { return '文件为空。'; }
        if (file.size > MAX_BYTES) {
            var mb = (MAX_BYTES / 1048576);
            return '文件超过 ' + mb + ' MB 限制。';
        }
        // 注意：file.type 由浏览器按扩展名推断，可被伪造，因此这里只做粗筛。
        // 真正的判定是服务端的 finfo MIME 检测 + getimagesize 内容验证。
        if (file.type && ALLOWED.indexOf(file.type) === -1) {
            return '只允许 JPG、PNG、GIF、WebP 图片。';
        }
        if (!ALLOWED_EXT.test(file.name || '')) {
            return '文件扩展名不在允许范围内。';
        }
        return '';
    }

    function uploadOne(file, done) {
        var pre = precheck(file);
        if (pre !== '') {
            logLine(file.name + '：' + pre, 'err');
            done();
            return;
        }

        var fd = new FormData();
        fd.append('image', file, file.name);
        fd.append('csrf_token', csrf);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', siteUrl('/upload.php'), true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('Accept', 'application/json');

        if (progress) { progress.hidden = false; }
        if (bar) { bar.style.width = '0'; }

        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable && bar) {
                bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
            }
        };

        xhr.onload = function () {
            var body = null;
            try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
            if (xhr.status >= 200 && xhr.status < 300 && body && body.ok) {
                // 直接在上传记录里给出直链与复制按钮 ——
                // 省掉"上传 → 刷新列表 → 找到那张图 → 再点复制"的往返。
                var d = body.data || {};
                logLine('已上传：' + file.name, 'ok', d.abs_url || '');
                refreshGrid();
            } else if (xhr.status === 401 || xhr.status === 403) {
                // 会话已失效：留在当前页会让用户对着一个用不了的界面对点。
                // 刷新页面即可让服务端的 require_admin() 接管并跳转到登录页。
                // 这不是新增功能，而是消除一个死胡同状态。
                logLine('登录状态已失效，正在跳转到登录页…', 'err');
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                var msg = (body && body.error) ? body.error : '操作失败，请稍后重试。';
                logLine(file.name + '：' + msg, 'err');
            }
            done();
        };
        xhr.onerror = function () {
            logLine(file.name + '：网络错误。', 'err');
            done();
        };
        xhr.send(fd);
    }


    /* ---------- 粘贴上传（Ctrl+V 贴图） ----------
       截图工具（微信/QQ/Win+Shift+S）复制后，直接粘贴即可上传。
       监听的是 document 而非 dropzone：用户从截图工具回来时焦点不一定在拖拽区。
       仅在存在 dropzone 的页面（后台）启用，避免前台误触。 */
    document.addEventListener('paste', function (ev) {
        if (!dz) { return; }
        var items = (ev.clipboardData && ev.clipboardData.items) || [];
        var files = [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind !== 'file') { continue; }
            var f = items[i].getAsFile();
            if (!f) { continue; }
            // 粘贴的截图通常没有文件名或名字是 "image.png"，统一补一个可辨识的名字
            if (!f.name || f.name === 'image.png' || f.name === 'blob') {
                var ext = (f.type && f.type.split('/')[1]) || 'png';
                if (ext === 'jpeg') { ext = 'jpg'; }
                f = new File([f], 'pasted-' + dateStamp() + '.' + ext, { type: f.type });
            }
            files.push(f);
        }
        if (files.length) {
            ev.preventDefault();
            toast(files.length > 1 ? ('粘贴上传 ' + files.length + ' 张') : '粘贴上传中…');
            handleFiles(files);
        }
    });

    function dateStamp() {
        var d = new Date();
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' +
               p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
    }






    /* ---------- 文件一致性自检 ----------
       两个动作：
         自检 -> 只报告
         清理 -> 删除"有文件无记录"的孤儿文件（需再次确认）
       刻意不自动删除：孤儿文件也可能是"文件比数据库新"造成的，
       自动删会丢图片。必须先看清再决定。 */
    (function () {
        var btn = document.getElementById('btn-consistency');
        if (!btn) { return; }

        var status = document.getElementById('consistency-status');
        var result = document.getElementById('consistency-result');
        var detail = document.getElementById('consistency-detail');
        var purgeWrap = document.getElementById('consistency-purge-wrap');
        var purgeBtn = document.getElementById('btn-purge-orphans');
        var dropWrap = document.getElementById('consistency-drop-wrap');
        var dropBtn = document.getElementById('btn-drop-broken');

        var orphans = [];

        function setStatus(t) { if (status) { status.textContent = t; } }

        function faq(n) { return n; }

        function render(data) {
            var lines = [];
            lines.push('目录中的图片文件：' + data.files + ' 个');
            lines.push('数据库中的记录：' + data.records + ' 条');

            if (data.orphan_count === 0 && data.missing_count === 0) {
                lines.push('');
                lines.push('结论：一致，没有发现问题。');
                if (purgeWrap) { purgeWrap.hidden = true; }
                if (dropWrap) { dropWrap.hidden = true; }
            } else {
                if (data.orphan_count > 0) {
                    lines.push('');
                    lines.push('孤儿文件 ' + data.orphan_count + ' 个（有文件、没记录）：');
                    for (var i = 0; i < Math.min(5, data.orphan_files.length); i++) {
                        lines.push('  ' + data.orphan_files[i]);
                    }
                    if (data.orphan_count > 5) { lines.push('  ...还有 ' + (data.orphan_count - 5) + ' 个'); }
                    if (purgeWrap) { purgeWrap.hidden = false; }
                } else {
                    if (purgeWrap) { purgeWrap.hidden = true; }
                }
                if (data.missing_count > 0) {
                    lines.push('');
                    lines.push('缺失文件 ' + data.missing_count + ' 条记录（有记录、没文件）：');
                    for (var j = 0; j < Math.min(5, data.missing_files.length); j++) {
                        lines.push('  ' + data.missing_files[j]);
                    }
                    if (data.missing_count > 5) { lines.push('  ...还有 ' + (data.missing_count - 5) + ' 个'); }
                    lines.push('');
                    lines.push('说明：文件已经不在磁盘上了，无法恢复。');
                    lines.push('这些记录本来也打不开，可以直接删掉（下方按钮）。');
                    lines.push('它们在图片列表里也会标为"文件已丢失"。');
                    if (dropWrap) { dropWrap.hidden = false; } else { /* noop */ }
                } else {
                    if (dropWrap) { dropWrap.hidden = true; }
                }
            }
            if (detail) { detail.textContent = lines.join('\n'); }
            if (result) { result.hidden = false; }
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            setStatus('检查中…');
            if (result) { result.hidden = true; }
            postForm(siteUrl('/check_consistency.php'), { action: 'check' }, csrfFromDom())
                .then(function (res) {
                    if (res.status === 401 || res.status === 403) { throw new Error('auth'); }
                    return res.json();
                })
                .then(function (body) {
                    btn.disabled = false;
                    if (!body || !body.ok) {
                        setStatus('失败：' + ((body && body.error) || '请稍后重试'));
                        return;
                    }
                    orphans = body.orphan_files || [];
                    setStatus('检查完成');
                    render(body);
                })
                .catch(function (e) {
                    btn.disabled = false;
                    if (e && e.message === 'auth') {
                        setStatus('登录状态已失效，正在刷新…');
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else {
                        setStatus('失败，请稍后重试');
                    }
                });
        });

        if (purgeBtn) {
            purgeBtn.addEventListener('click', function () {
                if (!orphans.length) { return; }
                if (!window.confirm('删除 ' + orphans.length +
                                    ' 个孤儿文件？如果其中有尚未入库的图片，它们会一并被删掉。')) {
                    return;
                }
                purgeBtn.disabled = true;
                setStatus('清理中…');
                postForm(siteUrl('/check_consistency.php'), { action: 'purge' }, csrfFromDom())
                    .then(function (res) {
                        if (res.status === 401 || res.status === 403) { throw new Error('auth'); }
                        return res.json();
                    })
                    .then(function (body) {
                        purgeBtn.disabled = false;
                        if (!body || !body.ok) {
                            setStatus('清理失败：' + ((body && body.error) || '请稍后重试'));
                            return;
                        }
                        setStatus('已删除 ' + body.deleted + ' 个文件，释放 ' + Math.round(body.freed / 1024) + ' KB');
                        toast('孤儿文件已清理');
                        // 重新自检以刷新结果
                        btn.click();
                    })
                    .catch(function () {
                        purgeBtn.disabled = false;
                        setStatus('清理失败，请稍后重试');
                    });
            });
        }

        if (dropBtn) {
            dropBtn.addEventListener('click', function () {
                if (!window.confirm('删除这些"有记录、没文件"的失效记录？\n\n' +
                                    '文件已经不在磁盘上，这些卡片本来也打不开，' +
                                    '删除记录不会影响任何还能看的图片。')) {
                    return;
                }
                dropBtn.disabled = true;
                setStatus('清理中…');
                postForm(siteUrl('/check_consistency.php'), { action: 'drop_broken' }, csrfFromDom())
                    .then(function (res) {
                        if (res.status === 401 || res.status === 403) { throw new Error('auth'); }
                        return res.json();
                    })
                    .then(function (body) {
                        dropBtn.disabled = false;
                        if (!body || !body.ok) {
                            setStatus('清理失败：' + ((body && body.error) || '请稍后重试'));
                            return;
                        }
                        setStatus('已删除 ' + body.deleted + ' 条失效记录');
                        toast('失效记录已清理');
                        btn.click();
                    })
                    .catch(function () {
                        dropBtn.disabled = false;
                        setStatus('清理失败，请稍后重试');
                    });
            });
        }
    })();

    /* ---------- 回到顶部 ----------
       仅在需要时出现：滚动超过一屏（约 400px）才显示。
       页面本来就不长时，出现"回到顶部"是多余的。 */
    (function () {
        var btn = document.getElementById('to-top');
        if (!btn) { return; }

        var THRESHOLD = 400;   // px，约为一个手机屏高

        // 这里刻意**不使用 requestAnimationFrame 做节流**。
        //
        // rAF 回调在页面不渲染时（后台标签、无头浏览器等）可能被推迟甚至不执行，
        // 而按钮的显隐是"最终必须生效"的状态更新 —— 一旦 rAF 没跑，
        // 按钮就会一直停在上一个状态。这是正确性问题，不是性能问题。
        //
        // 处理函数本身只做一次属性读取与一次 class 切换，开销远低于一次 rAF
        // 往返，因此直接执行即可。
        function update() {
            if (window.pageYOffset > THRESHOLD) {
                btn.hidden = false;
                btn.classList.add('is-shown');
            } else {
                btn.classList.remove('is-shown');
                // 等过渡结束再彻底移除，避免淡出过程中突然消失
                setTimeout(function () {
                    if (!btn.classList.contains('is-shown')) { btn.hidden = true; }
                }, 200);
            }
        }

        // passive: true —— 声明不会 preventDefault，浏览器可立即滚动，
        // 不等待本监听器返回。
        window.addEventListener('scroll', update, { passive: true });

        update();

        btn.addEventListener('click', function () {
            var reduce = window.matchMedia &&
                         window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            // 尊重系统的"减少动效"偏好：不做平滑滚动
            if (reduce) {
                window.scrollTo(0, 0);
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    })();

    /* ---------- 主题切换按钮 ----------
       三态循环：跟随系统 -> 浅色 -> 深色。
       状态由 assets/theme.js 负责持久化与应用（它在 <head> 同步执行）。 */
    (function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn || !window.ElationTheme) { return; }

        var LABEL = { auto: '跟随系统', light: '浅色', dark: '深色' };
        var ICON  = { auto: '\u25D0', light: '\u2600', dark: '\u263D' };   // ◐ ☀ ☾

        function render() {
            var pref = window.ElationTheme.get();
            btn.textContent = ICON[pref] || ICON.auto;
            btn.title = '主题：' + (LABEL[pref] || LABEL.auto) + '（点击切换）';
            btn.setAttribute('aria-label', btn.title);
        }

        btn.hidden = false;   // 有 JS 才显示，避免无 JS 时点了没反应
        render();

        btn.addEventListener('click', function () {
            var next = window.ElationTheme.cycle();
            render();
            toast('主题：' + (LABEL[next] || next));
        });
    })();

    /* ---------- 重建缩略图 ----------
       服务端每次只处理一批，这里循环调用并更新进度。
       这样大图库也不会因为单次请求超时而中断。 */
    (function () {
        var btn = document.getElementById('btn-rebuild-thumbs');
        if (!btn) { return; }

        var status = document.getElementById('rebuild-status');
        var box = document.getElementById('rebuild-progress');
        var bar = document.getElementById('rebuild-bar');
        var total = parseInt(btn.getAttribute('data-total'), 10) || 0;

        var built = 0, skipped = 0, missing = 0, failed = 0, offset = 0;

        function setStatus(text) { if (status) { status.textContent = text; } }

        function step() {
            postForm(siteUrl('/rebuild_thumbs.php'), { offset: offset, limit: 20 }, csrfFromDom())
                .then(function (res) {
                    if (res.status === 401 || res.status === 403) {
                        throw new Error('auth');
                    }
                    return res.json();
                })
                .then(function (body) {
                    if (!body || !body.ok) {
                        btn.disabled = false;
                        setStatus('失败：' + ((body && body.error) || '请稍后重试'));
                        if (box) { box.hidden = true; }
                        return;
                    }
                    built += body.built || 0;
                    skipped += body.skipped || 0;
                    missing += body.missing || 0;
                    failed += body.failed || 0;
                    offset = body.next || 0;

                    if (bar && body.total > 0) {
                        bar.style.width = Math.round((offset / body.total) * 100) + '%';
                    }
                    setStatus('已处理 ' + offset + ' / ' + body.total +
                              '（生成 ' + built + '，跳过 ' + skipped + '）');

                    if (body.done) {
                        btn.disabled = false;
                        if (box) { box.hidden = true; }
                        var tail = [];
                        if (missing > 0) { tail.push('原图缺失 ' + missing); }
                        if (failed > 0) { tail.push('失败 ' + failed); }
                        setStatus('完成：生成 ' + built + ' 个，跳过 ' + skipped + ' 个' +
                                  (tail.length ? '，' + tail.join('，') : ''));
                        toast('缩略图重建完成');
                    } else {
                        step();
                    }
                })
                .catch(function (e) {
                    btn.disabled = false;
                    if (box) { box.hidden = true; }
                    if (e && e.message === 'auth') {
                        setStatus('登录状态已失效，正在刷新…');
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else {
                        setStatus('失败，请稍后重试');
                    }
                });
        }

        btn.addEventListener('click', function () {
            if (btn.disabled) { return; }
            if (total === 0) { toast('还没有图片'); return; }
            if (!window.confirm('按当前设置重建全部缩略图？图多时可能需要一会儿。')) { return; }
            btn.disabled = true;
            built = skipped = missing = failed = offset = 0;
            if (box) { box.hidden = false; }
            if (bar) { bar.style.width = '0'; }
            setStatus('开始…');
            step();
        });
    })();

    /* ---------- 删除备份文件 ----------
       服务端只接受文件名，并在自己枚举出的允许列表里查找，
       因此这里传什么都不会造成路径穿越。 */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.js-del-backup') : null;
        if (!btn) { return; }
        var name = btn.getAttribute('data-name') || '';
        if (!name) { return; }
        if (!window.confirm('确定删除备份文件 ' + name + '？此操作不可撤销。')) { return; }

        btn.disabled = true;
        postForm(siteUrl('/backup_delete.php'), { name: name }, csrfFromDom())
            .then(function (res) {
                if (res.status === 401 || res.status === 403) {
                    toast('登录状态已失效，正在跳转…');
                    setTimeout(function () { window.location.reload(); }, 900);
                    return null;
                }
                return res.json();
            })
            .then(function (body) {
                if (body === null) { return; }
                if (body && body.ok) {
                    var tr = btn.closest('tr');
                    if (tr && tr.parentNode) { tr.parentNode.removeChild(tr); }
                    toast('已删除备份');
                } else {
                    btn.disabled = false;
                    toast((body && body.error) || '删除失败，请稍后重试。');
                }
            })
            .catch(function () {
                btn.disabled = false;
                toast('删除失败，请稍后重试。');
            });
    });

    /* ---------- 批量删除 ----------
       只在后台列表（#admin-grid[data-bulk]）启用。
       设计取舍：勾选状态不跨页保留 —— 跨页选择容易让用户漏删或误删，
       而"全选本页"已经覆盖了绝大多数使用场景。 */
    var grid = document.getElementById('admin-grid');
    var bulkbar = document.getElementById('bulkbar');
    var selectAll = document.getElementById('select-all');
    var bulkCount = document.getElementById('bulk-count');
    var bulkBtn = document.getElementById('bulk-delete');

    // 提到外层：refreshGrid() 在替换 innerHTML 之后需要重新同步操作条。
    // 若把它留在下面的 if 块内，refreshGrid 就看不到它。
    var syncBulkBar = function () {};

    if (grid && bulkbar && bulkBtn) {
        var isBulk = grid.getAttribute('data-bulk') === '1';

        function picks() {
            // 同样不能缓存 grid：列表容器被整体替换后，旧引用已经脱离文档。
            var live = document.getElementById('admin-grid');
            if (!live) { return []; }
            return Array.prototype.slice.call(live.querySelectorAll('.js-pick'));
        }
        function selected() {
            return picks().filter(function (c) { return c.checked; });
        }
        syncBulkBar = function () {
            // 每次都重新取：refreshGrid() 之后这些元素都是新的实例，
            // 用缓存的引用会改到已被销毁的旧节点上（界面上毫无变化）。
            var liveCount  = document.getElementById('bulk-count');
            var liveBtn    = document.getElementById('bulk-delete');
            var liveBar    = document.getElementById('bulkbar');
            var liveAll    = document.getElementById('select-all');

            var n = selected().length;
            if (liveCount) { liveCount.textContent = '已选 ' + n + ' 张'; }
            if (liveBtn) { liveBtn.disabled = (n === 0); }
            // 操作条常驻显示：用 class 标记"有选中项"来加强视觉提示，
            // 而不是隐藏它 —— 隐藏会让用户找不到批量删除入口。
            if (liveBar) {
                if (n > 0) { liveBar.classList.add('has-selection'); }
                else { liveBar.classList.remove('has-selection'); }
            }
            if (liveAll) {
                var all = picks();
                liveAll.checked = (all.length > 0 && n === all.length);
                liveAll.indeterminate = (n > 0 && n < all.length);
            }
        };
        // 注意：这里刻意**不使用缓存的元素引用**，而是每次都重新查询。
        //
        // 原因：refreshGrid() 会替换 #admin-list 的 innerHTML，而 #admin-grid
        // 与 #bulkbar 都在它内部 —— 旧的 grid 和按钮会被**整体销毁**，
        // 绑在它们身上的监听器随之消失。之后新生成的复选框没有监听器，
        // 表现为"勾选了但按钮不亮"，点删除也毫无反应（按钮还被销毁成旧的）。
        //
        // 因此改为**事件委托**：监听器挂在 document 上，无论 DOM 怎么换都在。
        // 每次事件里重新 getElementById，绝不跨刷新缓存引用。
        document.addEventListener('change', function (ev) {
            var t = ev.target;
            if (!t) { return; }

            if (t.classList && t.classList.contains('js-pick')) {
                syncBulkBar();
                return;
            }
            if (t.id === 'select-all') {
                var on = !!t.checked;
                picks().forEach(function (c) { c.checked = on; });
                syncBulkBar();
            }
        });

        document.addEventListener('click', function (ev) {
            var t = ev.target;
            // 兼容点到按钮内部元素的情况
            var btn = (t && t.closest) ? t.closest('#bulk-delete') : null;
            if (!btn) { return; }
            if (btn.disabled) { return; }

            var ids = selected().map(function (c) { return c.value; });
            if (!ids.length) { return; }
            if (!window.confirm('确定删除所选的 ' + ids.length + ' 张图片？此操作不可撤销。')) { return; }

            // 用事件里拿到的 btn，而不是缓存的 bulkBtn ——
            // 刷新之后缓存的那个已经被销毁，改它不会有任何视觉效果。
            btn.disabled = true;
            postForm(siteUrl('/delete_many.php'), { ids: ids.join(',') }, csrfFromDom())
                .then(function (res) {
                    if (res.status === 401 || res.status === 403) {
                        toast('登录状态已失效，正在跳转…');
                        setTimeout(function () { window.location.reload(); }, 900);
                        return null;
                    }
                    return res.json();
                })
                .then(function (body) {
                    if (body === null) { return; }
                    if (body && body.ok) {
                        // 立即把已删的卡片从 DOM 移除（视觉反馈），
                        // 然后重新拉取列表容器以修正计数与分页。
                        (body.deleted || []).forEach(function (id) {
                            var card = document.querySelector('.card[data-id="' + id + '"]');
                            if (card && card.parentNode) { card.parentNode.removeChild(card); }
                        });
                        toast('已删除 ' + (body.count || 0) + ' 张');
                        refreshGrid();
                    } else {
                        btn.disabled = false;
                        toast((body && body.error) || '删除失败，请稍后重试。');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    toast('删除失败，请稍后重试。');
                });
        });

        // 初次进入（含 refreshGrid 之后）同步一次状态
        syncBulkBar();
    }

    /* 上传成功后刷新列表（保持当前页，避免打断操作） */
    function refreshGrid() {
        fetch(window.location.pathname + window.location.search, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            // 刷新整个列表容器（含计数与分页），而非仅网格。
            // 关键：该容器在"零图片"时也存在，因此首张图片上传后能正常显示。
            var fresh = doc.getElementById('admin-list');
            var mine = document.getElementById('admin-list');
            if (fresh && mine) {
                mine.innerHTML = fresh.innerHTML;
                // 替换 innerHTML 会让 checkbox 全部重置，操作条需要跟着回零，
                // 否则会停留在"已选 N 张"而实际一个都没选。
                syncBulkBar();
            }
        }).catch(function () { /* 静默失败：用户可手动刷新 */ });
    }

    /* ---------- 前端错误提示（无需开发者工具） ----------
       目的：使用者不一定知道怎么打开 F12 控制台。
       这里把 JS 错误直接显示成一条可见横幅，并附「复制」按钮，
       方便把信息反馈出来。仅提示，不影响功能；同一错误只报一次，避免刷屏。 */
    (function () {
        var shown = {};

        /**
         * 组装一份便于反馈的完整报告。
         * 包含：错误文本、页面地址、浏览器标识、屏幕宽度、脚本是否执行过。
         * 其中"页面地址"最关键 —— 它能直接说明访问来源是否与脚本同源。
         */
        function buildReport(msg) {
            var lines = [];
            lines.push('=== Elation 前端错误报告 ===');
            lines.push('错误   : ' + msg);
            lines.push('页面   : ' + location.href);
            lines.push('来源   : ' + location.origin);
            lines.push('视口   : ' + window.innerWidth + 'x' + window.innerHeight +
                       ' (dpr ' + (window.devicePixelRatio || 1) + ')');
            lines.push('浏览器 : ' + (navigator.userAgent || 'unknown'));
            lines.push('脚本   : app.js ' + (window.__elationAppLoaded ? '已执行' : '未执行'));
            lines.push('时间   : ' + new Date().toISOString());
            lines.push('');
            lines.push('说明   : 本站页面只加载一个同源脚本 /assets/app.js。');
            lines.push('         若错误信息没有文件名与行号，它来自被注入页面的第三方');
            lines.push('         脚本（浏览器扩展 / VPN 代理 / 内容拦截器），与本站无关 ——');
            lines.push('         这类错误已被自动忽略，不会弹出横幅。');
            return lines.join('\n');
        }

        // app.js 执行到底部的标记：用于区分"脚本没加载"与"脚本加载后出错"
        window.__elationAppLoaded = true;

        function report(msg) {
            var key = String(msg).slice(0, 120);
            if (shown[key]) { return; }
            shown[key] = 1;

            var el = document.createElement('div');
            el.className = 'errbar';
            el.setAttribute('role', 'alert');

            var span = document.createElement('span');
            span.className = 'errbar-text';
            span.textContent = '页面脚本出错：' + msg;

            // 「复制」复制的是**更完整的报告**（含页面地址、浏览器标识、
            // 脚本是否真的执行过），而不是横幅上那行短文字 —— 排查时这些
            // 环境信息往往比错误本身更关键。
            var report = buildReport(msg);

            var copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'errbar-btn';
            copy.textContent = '复制';
            copy.addEventListener('click', function () {
                copyText(report).then(function () { copy.textContent = '已复制'; })
                                .catch(function () { copy.textContent = '复制失败'; });
            });

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'errbar-btn';
            close.textContent = '关闭';
            close.addEventListener('click', function () {
                if (el.parentNode) { el.parentNode.removeChild(el); }
            });

            el.appendChild(span);
            el.appendChild(copy);
            el.appendChild(close);
            document.body.appendChild(el);
            // 标记页面存在错误横幅：右下角的"回到顶部"按钮据此上移，避免被遮挡
            document.body.classList.add('has-errbar');
            close.addEventListener('click', function () {
                // 最后一个横幅关闭后移除标记，按钮回到原位
                setTimeout(function () {
                    if (!document.querySelector('.errbar')) {
                        document.body.classList.remove('has-errbar');
                    }
                }, 0);
            }, { once: true });
        }

        window.addEventListener('error', function (ev) {
            // 资源加载失败（img/script 404）也会走到这里，但没有 message。
            // 这类不是脚本错误，交给下面的资源监听处理，避免误报。
            if (!ev.message && !ev.filename) { return; }

            var msg = ev.message || '';

            // 过滤掉**可证明不来自本站脚本**的错误。
            //
            // 浏览器对跨源脚本抛出的错误只给一句 "Script error."，
            // 且不带文件名与行号。本站页面上只加载一个同源脚本
            // （/assets/app.js），因此凡是"没有文件名"的这类错误，
            // 都来自被注入页面的第三方脚本 —— 常见来源是手机上的
            // VPN/代理 App、浏览器扩展、内容拦截器。
            //
            // 这类错误与本站无关，弹横幅只会让使用者白白担心，
            // 因此在控制台留一条记录即可，不上横幅。
            if ((msg === 'Script error.' || msg === 'Script error') && !ev.filename) {
                if (window.console && console.debug) {
                    console.debug('[elation] 忽略一条不来自本站脚本的错误（通常是浏览器扩展或 VPN 注入）');
                }
                return;
            }

            var parts = [];
            parts.push(msg || '(无消息)');
            if (ev.filename) {
                parts.push(ev.filename.split('/').pop() + ':' + ev.lineno + ':' + ev.colno);
            }
            if (ev.error && ev.error.stack) {
                parts.push('stack: ' + String(ev.error.stack).split('\n').slice(0, 3).join(' / '));
            }
            report(parts.join(' · '));
        }, true);

        // 资源加载失败（例如 app.js 取不到）单独提示 —— 它同样是"功能没反应"的常见原因
        window.addEventListener('error', function (ev) {
            var t = ev.target;
            if (!t || !t.tagName) { return; }
            if (t.tagName !== 'SCRIPT' && t.tagName !== 'LINK' && t.tagName !== 'IMG') { return; }

            // 只报告**确实声明了地址**的资源。
            // 没有 src/href 属性时浏览器不会请求，也就不会有真实失败。
            var attr = (t.tagName === 'LINK') ? t.getAttribute('href') : t.getAttribute('src');
            if (!attr) { return; }

            // 去掉查询串后取文件名；若结果为空（例如地址本身就是目录），
            // 说明不是一次有意义的资源请求，静默忽略即可。
            var name = attr.split('?')[0].split('/').pop();
            if (!name) { return; }

            report('资源加载失败：' + name + '（' + t.tagName.toLowerCase() + '）');
        }, true);

        window.addEventListener('unhandledrejection', function (ev) {
            var r = ev.reason;
            var msg = (r && r.message) ? r.message : String(r);
            report('未处理的异步操作失败：' + msg);
        });
    })();
})();
