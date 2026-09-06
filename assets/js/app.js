/**
 * 🍉 ChiguaNana 提示词库 - 前端应用逻辑
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

    function isMobile() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    // ------------------------------------------------------- 列表：分页 / 无限下拉（移动端可切换）
    var LIST_MODE_KEY = 'opennana_list_mode';
    var listState = {
        mode: 'pager',
        page: 1,
        pages: 1,
        params: { q: '', model: '' },
        loading: false,
        observer: null
    };

    function getListMode() {
        try {
            var v = localStorage.getItem(LIST_MODE_KEY);
            if (v === 'pager' || v === 'infinite') return v;
        } catch (e) { /* ignore */ }
        return 'pager';
    }

    function setListMode(mode) {
        listState.mode = mode;
        try { localStorage.setItem(LIST_MODE_KEY, mode); } catch (e) { /* ignore */ }
        syncModeTabs();
        updateListChrome();
        bindListSentinel();
    }

    function ensureModeTabs() {
        var main = $('#main');
        if (!main || $('#view-mode-tabs')) return;
        var bar = document.createElement('div');
        bar.id = 'view-mode-tabs';
        bar.className = 'view-mode-tabs';
        bar.setAttribute('role', 'tablist');
        bar.innerHTML =
            '<button type="button" class="vtab" data-mode="pager" role="tab">分页</button>' +
            '<button type="button" class="vtab" data-mode="infinite" role="tab">下拉加载</button>';
        var ms = $('.masonry', main) || $('.empty', main);
        if (ms) main.insertBefore(bar, ms);
        else main.appendChild(bar);
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.vtab');
            if (!btn) return;
            var mode = btn.getAttribute('data-mode');
            if (!mode || mode === listState.mode) return;
            setListMode(mode);
            // 切到无限：从当前筛选第 1 页重载并开始追加；切到分页：重载当前页
            loadList({
                q: listState.params.q,
                model: listState.params.model,
                page: mode === 'infinite' ? 1 : listState.page
            }, true);
        });
        syncModeTabs();
    }

    function syncModeTabs() {
        $$('#view-mode-tabs .vtab').forEach(function (b) {
            b.classList.toggle('on', b.getAttribute('data-mode') === listState.mode);
        });
    }

    function ensureListSentinel() {
        var main = $('#main');
        if (!main) return null;
        var el = $('#list-sentinel');
        if (!el) {
            el = document.createElement('div');
            el.id = 'list-sentinel';
            el.className = 'load-sentinel';
            el.innerHTML = '<span class="load-tip">加载中…</span>';
            main.appendChild(el);
        }
        return el;
    }

    function updateListChrome() {
        var pager = $('.pager', $('#main'));
        var sent = $('#list-sentinel');
        if (listState.mode === 'infinite') {
            if (pager) pager.style.display = 'none';
            if (sent) {
                sent.style.display = listState.page < listState.pages ? '' : 'none';
                sent.classList.toggle('done', listState.page >= listState.pages);
                if (listState.page >= listState.pages) {
                    sent.innerHTML = '<span class="load-tip">已经到底啦</span>';
                } else {
                    sent.innerHTML = '<span class="load-tip">上拉加载更多</span>';
                }
            }
        } else {
            if (pager) pager.style.display = '';
            if (sent) sent.style.display = 'none';
        }
    }

    function bindListSentinel() {
        if (listState.observer) {
            listState.observer.disconnect();
            listState.observer = null;
        }
        if (listState.mode !== 'infinite') return;
        var el = ensureListSentinel();
        if (!el || !('IntersectionObserver' in window)) return;
        listState.observer = new IntersectionObserver(function (entries) {
            if (!entries[0] || !entries[0].isIntersecting) return;
            if (listState.loading || listState.page >= listState.pages) return;
            loadList({
                q: listState.params.q,
                model: listState.params.model,
                page: listState.page + 1,
                append: true
            }, false);
        }, { rootMargin: '240px 0px' });
        listState.observer.observe(el);
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
    function renderList(data, params, append) {
        var main = $('#main');
        if (!main) return;

        listState.page = data.page || 1;
        listState.pages = data.pages || 1;
        listState.params = { q: params.q || '', model: params.model || '' };

        var filtersEl = $('#filters');
        if (filtersEl && data.models) {
            filtersEl.innerHTML = filterChipsHtml(data.models, params.q || '', params.model || '', data.total_all);
        }
        var rl = $('.result-line', main);
        if (rl) rl.remove();

        ensureModeTabs();

        var ms = $('.masonry', main);
        if (!data.items.length && !append) {
            if (ms) {
                ms.outerHTML = '<div class="empty"><h3>没有匹配的内容</h3>' +
                    '<p>换个关键词，或<a href="' + window.location.pathname + '">返回全部</a></p></div>';
            }
            var oldNav0 = $('.pager', main);
            if (oldNav0) oldNav0.remove();
            updateListChrome();
            return;
        }

        if (append && ms) {
            ms.insertAdjacentHTML('beforeend', data.items.map(cardHtml).join(''));
            $$('.masonry img', ms).forEach(fadeImg);
        } else {
            if (!ms) return;
            ms.outerHTML = '<div class="masonry">' + data.items.map(cardHtml).join('') + '</div>';
            $$('.masonry img', main).forEach(fadeImg);
            if (listState.mode === 'pager') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        var oldNav = $('.pager', main);
        if (oldNav) oldNav.remove();
        if (data.pages > 1) {
            var baseParams = { q: data.q || '', model: data.model || '' };
            main.insertAdjacentHTML('beforeend', pagerHtml(data.page, data.pages, baseParams));
        }
        ensureListSentinel();
        updateListChrome();
        bindListSentinel();
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

    function loadList(params, pushState) {
        if (listState.loading) return;
        listState.loading = true;
        var append = !!params.append && listState.mode === 'infinite';
        var sent = $('#list-sentinel');
        if (sent && append) {
            sent.innerHTML = '<span class="load-tip">加载中…</span>';
            sent.classList.add('busy');
        }
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
                renderList(data, params, append);
                if (pushState) {
                    var u = buildUrl({
                        page: (listState.mode === 'pager' && data.page > 1) ? data.page : '',
                        model: params.model || '',
                        q: params.q || ''
                    });
                    history.pushState(params, '', u);
                }
                document.title = (data.q ? '搜索 “' + data.q + '” · ' : '') +
                    (data.model ? data.model + ' · ' : '') +
                    '🍉 ChiguaNana 提示词库';
            })
            .catch(function (e) {
                showToast('加载失败：' + e.message);
            })
            .then(function () {
                listState.loading = false;
                if (sent) sent.classList.remove('busy');
                updateListChrome();
            });
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
                var rl = $('.result-line'); if (rl) rl.remove();
                var pg = $('.pager'); if (pg) pg.remove();
                $$('.filters .chip').forEach(function (c) { c.classList.remove('on'); });
                var all = $('.filters .chip[data-model=""]'); if (all) all.classList.add('on');
                listState.page = 1;
                listState.pages = 1;
                listState.params = { q: '', model: '' };
                ensureListSentinel();
                updateListChrome();
                // 滚到第一条，方便立刻看到结果
                var first = $('.masonry .gcard') || $('.masonry');
                if (first && first.scrollIntoView) {
                    first.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
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

    // 相关推荐
    // PC：服务端同模型优先的固定列表
    // 移动端：随机无限下拉（/api/random.php?exclude=…）
    var relState = { seen: {}, loading: false, done: false, observer: null, exclude: 0 };

    function renderRelated() {
        var grid = $('#related-grid');
        var wrap = grid && grid.closest('.related');
        var dataEl = document.querySelector('.related-data');
        if (!grid) return;

        var items = [];
        if (dataEl) {
            try { items = JSON.parse(dataEl.textContent); } catch (e) { items = []; }
        }

        relState.exclude = parseInt((wrap && wrap.getAttribute('data-exclude-id')) || '0', 10) || 0;
        relState.seen = {};
        if (relState.exclude) relState.seen[relState.exclude] = 1;
        relState.done = false;
        relState.loading = false;

        if (isMobile()) {
            // 移动端：清空后随机无限加载
            grid.innerHTML = '';
            ensureRelatedSentinel();
            var tip = $('.related .sec-title span');
            if (tip) tip.textContent = '随便看看';
            bindRelatedSentinel();
            loadRelatedMore();
            return;
        }

        // PC：保留同模型相关
        if (!items || !items.length) {
            if (wrap) wrap.remove();
            return;
        }
        items.forEach(function (it) {
            if (it.id) relState.seen[it.id] = 1;
        });
        grid.innerHTML = Cards.grid(items);
        $$('.gcard-thumb img', grid).forEach(fadeImg);
        var sent = $('#related-sentinel');
        if (sent) sent.style.display = 'none';
    }

    function ensureRelatedSentinel() {
        var wrap = document.querySelector('.related');
        if (!wrap) return null;
        var el = $('#related-sentinel');
        if (!el) {
            el = document.createElement('div');
            el.id = 'related-sentinel';
            el.className = 'load-sentinel';
            wrap.appendChild(el);
        }
        el.style.display = '';
        el.classList.remove('done');
        el.innerHTML = '<span class="load-tip">上拉加载更多</span>';
        return el;
    }

    function bindRelatedSentinel() {
        if (relState.observer) {
            relState.observer.disconnect();
            relState.observer = null;
        }
        var el = ensureRelatedSentinel();
        if (!el || !('IntersectionObserver' in window)) return;
        relState.observer = new IntersectionObserver(function (entries) {
            if (!entries[0] || !entries[0].isIntersecting) return;
            loadRelatedMore();
        }, { rootMargin: '320px 0px' });
        relState.observer.observe(el);
    }

    function loadRelatedMore() {
        if (relState.loading || relState.done) return;
        var grid = $('#related-grid');
        if (!grid) return;
        relState.loading = true;
        var sent = $('#related-sentinel');
        if (sent) {
            sent.classList.add('busy');
            sent.innerHTML = '<span class="load-tip">加载中…</span>';
        }
        var ids = Object.keys(relState.seen);
        if (ids.length > 100) ids = ids.slice(-100); // 避免 exclude 过长
        var url = '/api/random.php?n=12' + (ids.length ? '&exclude=' + ids.join(',') : '');
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status)); })
            .then(function (data) {
                var batch = (data.items || []).filter(function (it) {
                    if (!it.id || relState.seen[it.id]) return false;
                    relState.seen[it.id] = 1;
                    return true;
                });
                if (!batch.length) {
                    relState.done = true;
                    if (sent) {
                        sent.classList.add('done');
                        sent.innerHTML = '<span class="load-tip">已经到底啦</span>';
                    }
                    return;
                }
                grid.insertAdjacentHTML('beforeend', Cards.grid(batch));
                $$('.gcard-thumb img', grid).forEach(fadeImg);
                if (sent) sent.innerHTML = '<span class="load-tip">上拉加载更多</span>';
            })
            .catch(function () {
                if (sent) sent.innerHTML = '<span class="load-tip">加载失败，上拉重试</span>';
            })
            .then(function () {
                relState.loading = false;
                if (sent) sent.classList.remove('busy');
            });
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

    // ------------------------------------------------------- 页脚脚本（未在 HTML 声明时动态加载，兼容旧静态页）
    function ensureFooterScript() {
        if (document.querySelector('script[src*="/assets/js/footer.js"]')) return;
        var s = document.createElement('script');
        s.src = '/assets/js/footer.js';
        s.defer = true;
        (document.body || document.documentElement).appendChild(s);
    }

    // ------------------------------------------------------- 启动
    function boot() {
        applyThemeEarly();
        ensureFooterScript();
        loadTrafficStats();
        listState.mode = getListMode();
        if ($('#main') && $('.masonry')) {
            ensureModeTabs();
            ensureListSentinel();
            updateListChrome();
            bindListSentinel();
            bindListEvents();
            // 如果 URL 带有非首页参数，立即加载对应数据
            var ps = new URLSearchParams(window.location.search);
            var q = ps.get('q') || '';
            var model = ps.get('model') || '';
            var page = parseInt(ps.get('page') || '1', 10);
            if (q || model || page > 1) {
                loadList({ q: q, model: model, page: page }, false);
            } else if (listState.mode === 'infinite') {
                // 首屏已有静态卡片：登记为第 1 页，后续滚动追加
                listState.page = 1;
                // pages 未知时先拉一次元信息（不替换首屏）
                fetch('/api/list.php?page=1&limit=1', { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) return;
                        listState.pages = data.pages || 1;
                        updateListChrome();
                    })
                    .catch(function () { /* ignore */ });
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