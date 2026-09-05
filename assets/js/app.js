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
    function cardHtml(it) {
        var cover = it.cover ? '<img src="' + escapeHtml(it.cover) +
            '" width="' + (it.cover_w || 400) + '" height="' + (it.cover_h || 400) +
            '" alt="' + escapeHtml(it.title) + '" loading="lazy" decoding="async">' :
            '<span class="ph">无图片</span>';
        var model = it.model ? '<span class="tag model">' + escapeHtml(it.model) + '</span>' : '';
        var video = it.media_type === 'video' ? '<span class="tag video">视频</span>' : '';
        var ratio = (it.cover_w && it.cover_h) ? ' style="aspect-ratio:' + it.cover_w + '/' + it.cover_h + '"' : '';
        return '' +
            '<a class="card" href="' + escapeHtml(it.url) + '">' +
                '<span class="thumb"' + ratio + '>' + cover + '</span>' +
                '<span class="body">' +
                    '<span class="t">' + escapeHtml(it.title) + '</span>' +
                    '<span class="meta">' + model + video +
                        '<span class="tag">' + escapeHtml(fmtDate(it.reviewed_at)) + '</span>' +
                    '</span>' +
                '</span>' +
            '</a>';
    }

    function resultLineHtml(total, q, model) {
        if (!q && !model) return '';
        var parts = ['筛选出 <b>' + total + '</b> 条'];
        if (q)   parts.push('（关键词：<b>' + escapeHtml(q) + '</b>）');
        if (model) parts.push('（模型：<b>' + escapeHtml(model) + '</b>）');
        parts.push('· <a href="' + window.location.pathname + '">清除筛选</a>');
        return '<p class="result-line">' + parts.join(' ') + '</p>';
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

    function filterChipsHtml(models, q, cur) {
        var html = '<div class="filters">';
        html += '<a class="chip' + (cur === '' ? ' on' : '') +
                '" href="' + buildUrl({ q: q || '' }) + '">全部</a>';
        Object.keys(models).forEach(function (m) {
            html += '<a class="chip' + (cur === m ? ' on' : '') + '" href="' +
                    buildUrl({ q: q || '', model: m }) + '">' +
                    escapeHtml(m) + '</a>';
        });
        html += '</div>';
        return html;
    }

    // ------------------------------------------------------- 列表加载
    function renderList(data, params) {
        var main = $('#main');
        if (!main) return;

        // 筛选条
        var filtersEl = $('#filters');
        if (filtersEl && data.models) {
            filtersEl.innerHTML = filterChipsHtml(data.models, params.q || '', params.model || '');
        }

        // 结果行
        var rl = $('.result-line', main);
        if (rl) rl.remove();
        if (data.q || data.model) {
            main.insertAdjacentHTML('afterbegin', resultLineHtml(data.total, data.q, data.model));
        }

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

    // ------------------------------------------------------- 详情页
    function bindDetailEvents() {
        $$('.copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text;
                if (btn.dataset.all) {
                    text = $$('.prompt-block pre').map(function (el) { return el.innerText; })
                        .join('\n\n---\n\n');
                } else {
                    var el = btn.dataset.target ? document.getElementById(btn.dataset.target) : null;
                    text = el ? el.innerText : '';
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
                var btn = $('.copy:not(.copy-all)') || $('.copy');
                if (btn) { btn.click(); e.preventDefault(); }
                return;
            }
            if (e.key === 'Escape') {
                var back = $('a.back'); if (back) back.click();
            }
        });

        $$('.detail-media img').forEach(fadeImg);
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