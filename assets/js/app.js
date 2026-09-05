/**
 * OpenNana 提示词库 - 前端应用逻辑
 *
 * 设计原则：
 *   - 不依赖任何三方库；用 vanilla JS + fetch
 *   - 不引入 inline script（除了早期的主题闪烁抑制）
 *   - 所有 URL 通过 History API 维护（无 # hash）
 *   - 懒加载图片，失败有兜底
 *
 * 接口契约：
 *   - GET /api/list.php?page=N&model=X&q=X
 *     { total, page, per_page, pages, models, items: [...] }
 *   - GET /api/search.php?q=X&page=N
 *     { total, page, per_page, pages, items: [...] }
 */
(function () {
    'use strict';

    // ------------------------------------------------------- 主题（早期抑制）
    function applyThemeEarly() {
        try {
            var t = localStorage.getItem('theme');
            var sys = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (t === 'dark' || (!t && sys)) {
                document.documentElement.className = 'dark';
            } else {
                document.documentElement.className = '';
            }
        } catch (e) { /* ignore */ }
    }

    // ------------------------------------------------------- 通用工具
    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtDate(s) {
        if (!s) return '';
        // ISO -> YYYY-MM-DD
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[1] + '-' + m[2] + '-' + m[3] : String(s);
    }

    function showToast(msg) {
        var t = $('#toast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('on');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { t.classList.remove('on'); }, 1500);
    }

    function buildUrl(params) {
        var usp = new URLSearchParams();
        Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] != null) usp.set(k, params[k]);
        });
        var s = usp.toString();
        return s ? '?' + s : window.location.pathname;
    }

    function pushHistory(params) {
        var url = buildUrl(params);
        if (url !== window.location.search && url !== window.location.pathname) {
            history.pushState(params, '', url);
        }
    }

    // ------------------------------------------------------- 卡片渲染
    // 列表卡片：统一走 cards.js 模块（.gcard，与详情页相关推荐同款）
    function cardHtml(it) {
        return Cards.html(it);
    }

    function pagerHtml(page, pages, baseParams) {
        if (pages <= 1) return '';
        function link(i) {
            var p = Object.assign({}, baseParams, { page: i });
            return '<a href="' + buildUrl(p) + '" data-page="' + i + '">' + i + '</a>';
        }
        var cur = function (i) { return '<span class="cur">' + i + '</span>'; };
        var from = Math.max(1, page - 2);
        var to = Math.min(pages, from + 4);
        from = Math.max(1, to - 4);
        var html = '<nav class="pager">';
        if (page > 1) html += '<a href="' + buildUrl(Object.assign({}, baseParams, { page: page - 1 })) + '" data-page="' + (page - 1) + '">上一页</a>';
        if (from > 1) html += link(1) + '<span class="gap">…</span>';
        for (var i = from; i <= to; i++) html += (i === page) ? cur(i) : link(i);
        if (to < pages) html += '<span class="gap">…</span>' + link(pages);
        if (page < pages) html += '<a href="' + buildUrl(Object.assign({}, baseParams, { page: page + 1 })) + '" data-page="' + (page + 1) + '">下一页</a>';
        html += '</nav>';
        return html;
    }

    // 生成筛选 chip（必须带 data-model，否则委托点击读不到模型 → 只能切换一次）
    // 只返回 chip 本身，不再包一层 .filters（#filters 自身已是 .filters 容器）
    function filterChipsHtml(models, q, cur, totalAll) {
        function n(x) { return (x || 0).toLocaleString('en-US'); }
        var html = '<a class="chip' + (cur === '' ? ' on' : '') +
                   '" href="' + buildUrl({ q: q || '' }) + '" data-model="">全部' +
                   (totalAll ? '<span class="n">' + n(totalAll) + '</span>' : '') + '</a>';
        Object.keys(models).forEach(function (m) {
            html += '<a class="chip' + (cur === m ? ' on' : '') + '" href="' +
                    buildUrl({ q: q || '', model: m }) + '" data-model="' + escapeHtml(m) + '">' +
                    escapeHtml(m) + '<span class="n">' + n(models[m]) + '</span></a>';
        });
        return html;
    }

    // ------------------------------------------------------- 列表加载
    function renderList(data, params) {
        var main = $('#main');
        if (!main) return;

        // 筛选条（chip 自带计数；不再输出"筛选出 N 条"结果行）
        var filtersEl = $('#filters');
        if (filtersEl && data.models) {
            filtersEl.innerHTML = filterChipsHtml(data.models, params.q || '', params.model || '', data.total_all);
        }
        // 兼容：清掉可能存在的旧结果行
        var rl = $('.result-line', main);
        if (rl) rl.remove();

        // 卡片
        var ms = $('.masonry', main);
        if (!ms) return;
        if (!data.items.length) {
            ms.outerHTML = '<div class="empty"><h3>没有匹配的内容</h3>' +
                '<p>换个关键词，或<a href="' + window.location.pathname + '">返回全部</a></p></div>';
            return;
        }
        ms.outerHTML = '<div class="masonry">' + data.items.map(cardHtml).join('') + '</div>';

        // 分页
        var oldNav = $('.pager', main);
        if (oldNav) oldNav.remove();
        if (data.pages > 1) {
            var baseParams = { q: data.q || '', model: data.model || '' };
            main.insertAdjacentHTML('beforeend', pagerHtml(data.page, data.pages, baseParams));
        }

        // 图片淡入 & 错误兜底
        $$('.masonry img, .detail-media img', main).forEach(fadeImg);

        // 列表广告占位（ads.js 提供，未加载时忽略）
        if (window.injectListAds) window.injectListAds();

        // 滚动到顶
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function fadeImg(img) {
        if (img.complete && img.naturalWidth) { img.classList.add('on'); return; }
        img.addEventListener('load', function () { img.classList.add('on'); }, { once: true });
        img.addEventListener('error', function () {
            var box = img.parentNode;
            if (box && !box.querySelector('.ph')) {
                var s = document.createElement('span');
                s.className = 'ph'; s.textContent = '图片缺失';
                box.appendChild(s);
            }
            img.style.display = 'none';
        }, { once: true });
    }

    var loading = false;
    function loadList(params, pushState) {
        if (loading) return;
        loading = true;
        var url = '/api/list.php?' +
            new URLSearchParams({
                page: params.page || 1,
                limit: params.limit || 36,
                model: params.model || '',
                q: params.q || ''
            }).toString();
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status)); })
            .then(function (data) {
                renderList(data, params);
                if (pushState) {
                    var u = buildUrl({
                        page: data.page > 1 ? data.page : '',
                        model: params.model || '',
                        q: params.q || ''
                    });
                    history.pushState(params, '', u);
                }
                document.title = (data.q ? '搜索 “' + data.q + '” · ' : '') +
                    (data.model ? data.model + ' · ' : '') +
                    'OpenNana 提示词库';
            })
            .catch(function (e) {
                showToast('加载失败：' + e.message);
            })
            .then(function () { loading = false; });
    }

    // ------------------------------------------------------- 随机（手气不错）
    function loadRandom() {
        var btn = $('#random-btn');
        if (btn) { btn.disabled = true; btn.textContent = '🎲 抽取中…'; }
        function done() { if (btn) { btn.disabled = false; btn.textContent = '🎲 手气不错'; } }
        fetch('/api/random.php?n=36', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status)); })
            .then(function (data) {
                var ms = $('.masonry');
                if (ms && data.items && data.items.length) {
                    ms.outerHTML = '<div class="masonry">' + data.items.map(cardHtml).join('') + '</div>';
                    $$('.masonry img').forEach(fadeImg);
                    if (window.injectListAds) window.injectListAds();
                }
                // 清掉筛选/结果行与分页，回到“纯随机”视图
                var rl = $('.result-line'); if (rl) rl.remove();
                var pg = $('.pager'); if (pg) pg.remove();
                $$('.filters .chip').forEach(function (c) { c.classList.remove('on'); });
                var all = $('.filters .chip[data-model=""]'); if (all) all.classList.add('on');
                window.scrollTo({ top: 0, behavior: 'smooth' });
                showToast('已随机抽取 ' + (data.count || data.items.length) + ' 条');
            })
            .catch(function (e) { showToast('随机失败：' + e.message); })
            .then(done);
    }

    // ------------------------------------------------------- 页脚流量统计
    // 拉取 /api/tj.php（act=api 默认记一次访问 → PV/IP 自增）；Redis 不可用则静默隐藏
    function loadTrafficStats() {
        var el = $('#traffic-stats');
        if (!el) return;
        fetch('/api/tj.php?act=api', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || j.code !== 0 || j.redis !== 'ok' || !j.data) return;
                var d = j.data;
                el.innerHTML =
                    '今日 IP <b>' + d.today_ip + '</b> · 今日 PV <b>' + d.today_pv + '</b> · ' +
                    '总 IP <b>' + d.total_ip + '</b> · 总 PV <b>' + d.total_pv + '</b>';
            })
            .catch(function () { /* 忽略：统计失败不影响页面 */ });
    }

    // ------------------------------------------------------- 事件
    function bindListEvents() {
        var rbtn = $('#random-btn');
        if (rbtn) rbtn.addEventListener('click', loadRandom);

        // 模型筛选（事件委托，main 容错包含）
        document.addEventListener('click', function (e) {
            var chip = e.target.closest && e.target.closest('.filters .chip');
            if (chip && chip.href) {
                e.preventDefault();
                var u = new URL(chip.href, window.location.origin);
                var q = u.searchParams.get('q') || '';
                var model = chip.getAttribute('data-model') || '';
                loadList({ q: q, model: model, page: 1 }, true);
                return;
            }
            var page = e.target.closest && e.target.closest('.pager a');
            if (page && page.dataset.page) {
                e.preventDefault();
                var ps = new URLSearchParams(window.location.search);
                loadList({
                    page: parseInt(page.dataset.page, 10),
                    model: ps.get('model') || '',
                    q: ps.get('q') || ''
                }, true);
            }
        });

        // 搜索表单
        var form = $('.search-bar');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = form.querySelector('input[name="q"]');
                var q = (input ? input.value : '').trim();
                var modelInput = form.querySelector('input[name="model"]');
                var model = modelInput ? modelInput.value : '';
                loadList({ q: q, model: model, page: 1 }, true);
            });
        }

        // 历史前进后退
        window.addEventListener('popstate', function () {
            var ps = new URLSearchParams(window.location.search);
            loadList({
                page: parseInt(ps.get('page') || '1', 10),
                model: ps.get('model') || '',
                q: ps.get('q') || ''
            }, false);
        });

        // 首屏图片淡入
        $$('.masonry img, .detail-media img').forEach(fadeImg);

        // 键盘
        document.addEventListener('keydown', function (e) {
            var el = document.activeElement;
            var inForm = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
            if (inForm) {
                if (e.key === 'Escape' && el.blur) el.blur();
                return;
            }
            if (e.key === '/') {
                var s = $('.search-bar input[name="q"]'); if (s) { s.focus(); s.select(); e.preventDefault(); }
                return;
            }
        });
    }

    // ------------------------------------------------------- Lightbox 全屏看图
    var Lightbox = (function () {
        var el = null, img = null, counter = null, prevBtn = null, nextBtn = null, urls = [], idx = 0;
        function ensure() {
            if (el) return;
            el = document.createElement('div');
            el.className = 'lb';
            el.innerHTML =
                '<button class="lb-close" type="button" aria-label="关闭">×</button>' +
                '<button class="lb-prev" type="button" aria-label="上一张">‹</button>' +
                '<span class="lb-fig"><img class="lb-img" alt=""></span>' +
                '<button class="lb-next" type="button" aria-label="下一张">›</button>' +
                '<div class="lb-count"></div>';
            document.body.appendChild(el);
            img      = el.querySelector('.lb-img');
            counter  = el.querySelector('.lb-count');
            prevBtn  = el.querySelector('.lb-prev');
            nextBtn  = el.querySelector('.lb-next');
            el.querySelector('.lb-close').addEventListener('click', close);
            prevBtn.addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
            nextBtn.addEventListener('click', function (e) { e.stopPropagation(); step(1); });
            el.addEventListener('click', function (e) { if (e.target === el) close(); }); // 点背景关闭
            img.addEventListener('click', function (e) { e.stopPropagation(); });
        }
        function render() {
            img.src = urls[idx] || '';
            var multi = urls.length > 1;
            counter.textContent = (idx + 1) + ' / ' + urls.length;
            counter.style.display = multi ? '' : 'none';
            prevBtn.style.display = multi ? '' : 'none';
            nextBtn.style.display = multi ? '' : 'none';
        }
        function open(list, i) {
            ensure();
            urls = (list || []).slice();
            if (!urls.length) return;
            idx = Math.max(0, Math.min(i || 0, urls.length - 1));
            render();
            el.classList.add('on');
            document.body.style.overflow = 'hidden';   // 锁背景滚动
        }
        function close() {
            if (!el) return;
            el.classList.remove('on');
            document.body.style.overflow = '';
            img.removeAttribute('src');
        }
        function step(d) {
            if (urls.length < 2) return;
            idx = (idx + d + urls.length) % urls.length;
            render();
        }
        function isOpen() { return !!(el && el.classList.contains('on')); }
        return { open: open, close: close, step: step, isOpen: isOpen };
    })();

    // 详情图片：点击打开 lightbox；收集 data-full 原图地址，多图可左右切换
    function bindLightbox() {
        var imgs = $$('.detail-media .dimg');
        if (!imgs.length) return;
        var urls = imgs.map(function (im) { return im.getAttribute('data-full') || im.currentSrc || im.src; });
        imgs.forEach(function (im, i) {
            im.addEventListener('click', function () { Lightbox.open(urls, i); });
        });
    }

    // ------------------------------------------------------- 提示词 中/英 tab
    function bindPromptTabs() {
        var tabs = $$('.prompt-tabs .ptab');
        if (!tabs.length) return;
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-tab');
                tabs.forEach(function (b) { b.classList.toggle('on', b === btn); });
                $$('.ppanel').forEach(function (p) { p.classList.toggle('on', p.id === 'panel-' + target); });
            });
        });
    }

    // 相关推荐：服务端内嵌 .related-data JSON，客户端用 cards.js 统一渲染（与首页同款卡片）
    function renderRelated() {
        var grid = $('#related-grid');
        var dataEl = document.querySelector('.related-data');
        if (!grid || !dataEl) return;
        var items = [];
        try { items = JSON.parse(dataEl.textContent); } catch (e) { return; }
        if (!items || !items.length) {
            var wrap = grid.closest('.related');
            if (wrap) wrap.remove();
            return;
        }
        grid.innerHTML = Cards.grid(items);
        $$('.gcard-thumb img', grid).forEach(fadeImg);
    }

    // ------------------------------------------------------- 详情页
    function bindDetailEvents() {
        renderRelated();
        $$('.copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text;
                if (btn.dataset.all) {
                    // 用 textContent：tab 隐藏面板是 display:none，innerText 会返回空串
                    text = $$('.prompt-block pre').map(function (el) { return el.textContent; })
                        .join('\n\n---\n\n');
                } else {
                    var el = btn.dataset.target ? document.getElementById(btn.dataset.target) : null;
                    text = el ? el.textContent : '';
                }
                function ok() {
                    var old = btn.textContent;
                    btn.textContent = '已复制'; btn.classList.add('ok');
                    setTimeout(function () { btn.textContent = old; btn.classList.remove('ok'); }, 1400);
                }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(ok, fallback);
                } else fallback();
                function fallback() {
                    var ta = document.createElement('textarea');
                    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    document.body.removeChild(ta); ok();
                }
            });
        });

        // 键盘快捷键
        document.addEventListener('keydown', function (e) {
            // lightbox 打开时优先：←→ 切图、Esc 关闭，并拦截其余按键（不触发翻条目/返回）
            if (Lightbox.isOpen()) {
                if (e.key === 'Escape')     { Lightbox.close(); e.preventDefault(); return; }
                if (e.key === 'ArrowLeft')  { Lightbox.step(-1); e.preventDefault(); return; }
                if (e.key === 'ArrowRight') { Lightbox.step(1);  e.preventDefault(); return; }
                return;
            }
            var el = document.activeElement;
            var inForm = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
            if (inForm) {
                if (e.key === 'Escape' && el.blur) el.blur();
                return;
            }
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                var nav = $('.nav-row');
                if (nav) {
                    var a = nav.querySelector(e.key === 'ArrowLeft' ? 'a:first-child' : 'a:last-child');
                    if (a && a.href) { window.location.href = a.href; e.preventDefault(); }
                }
                return;
            }
            if (e.key.toLowerCase() === 'c') {
                // 复制当前激活 tab 的提示词（无 tab 时复制首段）
                var active = $('.ppanel.on pre');
                var btn = active
                    ? $('.copy[data-target="' + active.id + '"]')
                    : ($('.copy:not(.copy-all)') || $('.copy'));
                if (btn) { btn.click(); e.preventDefault(); }
                return;
            }
            if (e.key === 'Escape') {
                var back = $('a.back'); if (back) back.click();
            }
        });

        $$('.detail-media img').forEach(fadeImg);
        bindLightbox();
        bindPromptTabs();
    }

    // ------------------------------------------------------- 主题切换
    function bindThemeToggle() {
        var btn = $('#theme-toggle');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var toDark = !document.documentElement.classList.contains('dark');
            document.documentElement.classList.toggle('dark', toDark);
            try { localStorage.setItem('theme', toDark ? 'dark' : 'light'); } catch (e) {}
        });
    }

    // ------------------------------------------------------- 启动
    function boot() {
        applyThemeEarly();
        if ($('#main') && $('.masonry')) {
            bindListEvents();
            loadTrafficStats();
            // 如果 URL 带有非首页参数，立即加载对应数据
            var ps = new URLSearchParams(window.location.search);
            var q = ps.get('q') || '';
            var model = ps.get('model') || '';
            var page = parseInt(ps.get('page') || '1', 10);
            if (q || model || page > 1) {
                loadList({ q: q, model: model, page: page }, false);
            }
        } else if ($('.detail')) {
            bindDetailEvents();
        }
        bindThemeToggle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();