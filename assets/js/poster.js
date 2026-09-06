/**
 * 🍉 ChiguaNana 提示词库 - 分享 & 竖版海报
 *
 * 功能：
 *   - 详情页"分享"按钮打开分享弹窗
 *   - 竖版海报：上图（同源代理，避免画布被跨域污染）+ 下提示词（中/英可选）+ 底部二维码
 *   - 海报预览；保存海报图 / 系统分享海报图
 *   - 常见分享：微博、二维码、复制链接、系统分享
 *
 * 依赖：assets/js/qrcode.js（kazuhikoarase, MIT，本地 bundle）
 * 数据：页面内 <script type="application/json" id="share-data">
 */
(function () {
    'use strict';

    var FONT = '-apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Segoe UI", Roboto, sans-serif';
    var W = 750;          // 海报宽度（竖版）
    var PAD = 44;
    var CONTENT_W = W - PAD * 2;

    var data = null;
    var lang = 'zh';
    var posterCanvas = null;

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    function toast(msg) {
        var t = $('#toast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('on');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.classList.remove('on'); }, 1600);
    }

    // ------------------------------------------------------- 文本换行（中日韩按字、拉丁按词）
    function tokenize(s) {
        var out = [], buf = '';
        var arr = Array.from(s);
        var cjk = /[\u3000-\u303f\u3040-\u30ff\u3400-\u4dbf\u4e00-\u9fff\uf900-\ufaff\uff00-\uffef]/;
        for (var i = 0; i < arr.length; i++) {
            var ch = arr[i];
            if (cjk.test(ch)) { if (buf) { out.push(buf); buf = ''; } out.push(ch); }
            else if (ch === ' ') { if (buf) { out.push(buf); buf = ''; } out.push(' '); }
            else buf += ch;
        }
        if (buf) out.push(buf);
        return out;
    }

    function wrapText(ctx, text, maxWidth) {
        var lines = [];
        String(text).split('\n').forEach(function (ln) {
            var line = '';
            tokenize(ln).forEach(function (tk) {
                var cand = line + tk;
                if (ctx.measureText(cand).width > maxWidth && line.trim()) {
                    lines.push(line.replace(/\s+$/, ''));
                    line = (tk === ' ') ? '' : tk;
                } else {
                    line = cand;
                }
            });
            lines.push(line.replace(/\s+$/, ''));
        });
        return lines;
    }

    function roundRectPath(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function pickPrompt(d, lg) {
        var list = (d && d.prompts) || [];
        for (var i = 0; i < list.length; i++) if (list[i].lang === lg) return list[i];
        return list[0] || { lang: lg, text: '' };
    }

    // ------------------------------------------------------- 二维码绘制
    function drawQR(ctx, text, x, y, size) {
        var qr = qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        var n = qr.getModuleCount();
        var quiet = Math.max(2, Math.round(n * 0.06));
        var total = n + quiet * 2;
        var cell = size / total;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(x, y, size, size);
        ctx.fillStyle = '#101418';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) {
                    ctx.fillRect(x + (c + quiet) * cell, y + (r + quiet) * cell, Math.ceil(cell), Math.ceil(cell));
                }
            }
        }
    }

    function qrDataUrl(text, px) {
        var c = document.createElement('canvas');
        c.width = px; c.height = px;
        var ctx = c.getContext('2d');
        drawQR(ctx, text, 0, 0, px);
        return c.toDataURL('image/png');
    }

    // ------------------------------------------------------- 海报绘制
    var IMG_TIMEOUT = 15000;   // 单次加载超时（代理回源可能较慢，避免 loading 无限转）

    function loadImageOnce(src) {
        return new Promise(function (resolve) {
            var im = new Image();
            var t = setTimeout(function () {
                im.onload = im.onerror = null;
                im.src = '';
                resolve(null);
            }, IMG_TIMEOUT);
            im.onload = function () { clearTimeout(t); resolve(im); };
            im.onerror = function () { clearTimeout(t); resolve(null); };
            im.src = src;
        });
    }

    function loadImage(src) {
        if (!src) return Promise.resolve(null);
        // 失败/超时自动重试一次，减少代理偶发抖动导致海报缺图
        return loadImageOnce(src).then(function (im) {
            return im || loadImageOnce(src);
        });
    }

    function buildPoster(d, lg) {
        var coverSrc = d.cover ? ('/api/imgproxy.php?u=' + encodeURIComponent(d.cover)) : '';
        return loadImage(coverSrc).then(function (imgEl) {
            var measure = document.createElement('canvas').getContext('2d');

            // 标题 / 正文 行
            measure.font = '700 34px ' + FONT;
            var titleLines = wrapText(measure, d.title || '', CONTENT_W).slice(0, 2);
            var prompt = pickPrompt(d, lg);
            measure.font = '400 25px ' + FONT;
            var BODY_MAX = 12;
            var bodyAll = wrapText(measure, prompt.text || '(无提示词)', CONTENT_W);
            var truncated = bodyAll.length > BODY_MAX;
            var bodyLines = bodyAll.slice(0, BODY_MAX);
            if (truncated) bodyLines[bodyLines.length - 1] = bodyLines[bodyLines.length - 1].replace(/[\s.,，。!?！？]*$/, '') + ' …';

            // 版式高度
            var iw = imgEl ? imgEl.naturalWidth : 0;
            var ih = imgEl ? imgEl.naturalHeight : 0;
            // 100% 完整展示（不裁剪）：按原始比例以内容宽度展开；
            // 超长图限高 IMG_MAX_H，等比缩小后水平居中，仍保留完整画面
            var IMG_MAX_H = 1000;
            var imgH = 0, imgW = 0;
            if (iw && ih) {
                imgH = Math.round(Math.min(CONTENT_W * ih / iw, IMG_MAX_H));
                imgW = Math.round(iw * imgH / ih);
            }

            var y = PAD;                      // 顶部留白（与左右内边距对称，不加装饰条）
            if (imgH > 0) { y += imgH + 30; }
            var titleY = y; y += titleLines.length * 46 + 12;
            var metaY = 0;
            if (d.model) { metaY = y; y += 36; }
            var bodyY = y; y += bodyLines.length * 40 + 16;
            y += 14;                          // 分隔线
            var footY = y; y += 150 + PAD;    // 底部二维码区
            var H = y;

            var c = document.createElement('canvas');
            c.width = W; c.height = H;
            var ctx = c.getContext('2d');

            // 背景（纯白，无顶部装饰条）
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);

            // 封面图（圆角，完整绘制不裁剪；窄于内容宽时水平居中）
            if (imgEl && imgH > 0) {
                var dx = PAD + Math.round((CONTENT_W - imgW) / 2);
                ctx.save();
                roundRectPath(ctx, dx, PAD, imgW, imgH, 16);
                ctx.clip();
                ctx.drawImage(imgEl, dx, PAD, imgW, imgH);
                ctx.restore();
            }

            // 标题
            ctx.fillStyle = '#1f2430';
            ctx.font = '700 34px ' + FONT;
            ctx.textBaseline = 'top';
            titleLines.forEach(function (ln, i) { ctx.fillText(ln, PAD, titleY + i * 46); });

            // 模型标签
            if (d.model) {
                ctx.font = '600 22px ' + FONT;
                ctx.fillStyle = '#2563eb';
                ctx.fillText('● ' + d.model, PAD, metaY);
            }

            // 提示词正文
            ctx.font = '400 25px ' + FONT;
            ctx.fillStyle = '#3a4152';
            bodyLines.forEach(function (ln, i) { ctx.fillText(ln, PAD, bodyY + i * 40); });

            // 分隔线
            ctx.strokeStyle = '#e4e7ec'; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.moveTo(PAD, footY - 14); ctx.lineTo(W - PAD, footY - 14); ctx.stroke();

            // 底部：二维码 + 站点信息
            drawQR(ctx, d.page_url, PAD, footY, 150);
            var tx = PAD + 150 + 26;
            ctx.fillStyle = '#1f2430'; ctx.font = '700 28px ' + FONT;
            ctx.fillText(d.site_name || '🍉 ChiguaNana 提示词库', tx, footY + 8);
            ctx.fillStyle = '#6b7280'; ctx.font = '400 20px ' + FONT;
            var urlTxt = d.page_url.replace(/^https?:\/\//, '');
            while (ctx.measureText(urlTxt).width > CONTENT_W - 176 && urlTxt.length > 8) urlTxt = urlTxt.slice(0, -2);
            ctx.fillText(urlTxt, tx, footY + 52);
            ctx.fillText('长按 / 扫描二维码查看原文与完整提示词', tx, footY + 88);
            ctx.fillStyle = '#9aa3b2'; ctx.font = '400 18px ' + FONT;
            ctx.fillText('语言：' + (lg === 'zh' ? '中文' : 'English'), tx, footY + 122);

            return { canvas: c, coverLoaded: !!imgEl };
        });
    }

    // ------------------------------------------------------- 分享动作
    function canvasToBlob(c) {
        return new Promise(function (res) { c.toBlob(res, 'image/jpeg', 0.92); });
    }

    function downloadPoster() {
        if (!posterCanvas) { toast('海报生成中，请稍候…'); return; }
        var a = document.createElement('a');
        a.href = posterCanvas.toDataURL('image/jpeg', 0.92);
        a.download = (data.slug || 'poster') + '-poster.jpg';
        document.body.appendChild(a); a.click(); a.remove();
        toast('海报已保存');
    }

    function sharePosterImage() {
        if (!posterCanvas) { toast('海报生成中，请稍候…'); return; }
        canvasToBlob(posterCanvas).then(function (blob) {
            if (!blob) { downloadPoster(); return; }
            var file = new File([blob], (data.slug || 'poster') + '-poster.jpg', { type: 'image/jpeg' });
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({ files: [file], title: data.title, text: data.title }).catch(function () {});
            } else {
                downloadPoster();
            }
        });
    }

    function shareWeibo() {
        var u = 'https://service.weibo.com/share/share.php?url=' + encodeURIComponent(data.page_url) +
                '&title=' + encodeURIComponent((data.title || '') + ' - 🍉 ChiguaNana 提示词');
        window.open(u, '_blank', 'noopener,width=680,height=520');
    }

    function showQr() {
        var box = $('#qr-box');
        var img = $('#qr-img');
        if (!box || !img) return;
        img.src = qrDataUrl(data.page_url, 360);
        box.classList.toggle('on');
    }

    function copyLink() {
        function ok() { toast('链接已复制'); }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(data.page_url).then(ok, fallback);
        } else fallback();
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = data.page_url; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta); ok();
        }
    }

    function systemShare() {
        if (navigator.share) {
            navigator.share({ title: data.title, text: (data.title || '') + ' - 🍉 ChiguaNana 提示词', url: data.page_url })
                .catch(function () {});
        } else {
            copyLink();
        }
    }

    // ------------------------------------------------------- 弹窗
    var previewSeq = 0;

    // 加载动画 / 失败重试浮层：由 JS 动态注入，无需改动已生成的静态页
    function ensureOverlay(wrap) {
        var ov = wrap.querySelector('.poster-ov');
        if (!ov) {
            ov = document.createElement('div');
            ov.className = 'poster-ov';
            var sp = document.createElement('div');
            sp.className = 'poster-spin';
            var txt = document.createElement('p');
            txt.className = 'poster-ov-txt';
            txt.textContent = '海报生成中…';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn poster-retry';
            btn.textContent = '生成失败，点击重试';
            btn.addEventListener('click', refreshPreview);
            ov.appendChild(sp); ov.appendChild(txt); ov.appendChild(btn);
            wrap.appendChild(ov);
        }
        return ov;
    }

    function markPreviewError(seq) {
        if (seq !== previewSeq) return;
        var wrap = $('.poster-preview');
        if (wrap) { wrap.classList.remove('loading'); wrap.classList.add('error'); }
    }

    function refreshPreview() {
        var wrap = $('.poster-preview');
        var img = $('#poster-preview');
        if (!img) return;
        var seq = ++previewSeq;
        posterCanvas = null;                      // 生成期间禁用保存/分享，避免拿到旧图
        img.classList.remove('on');
        img.removeAttribute('src');               // 先隐藏空 <img>，杜绝一打开就显示破图
        if (wrap) {
            ensureOverlay(wrap);
            wrap.classList.remove('error');
            wrap.classList.add('loading');        // 先出加载动画
        }
        buildPoster(data, lang).then(function (r) {
            if (seq !== previewSeq) return;       // 已切换语言/重新打开，丢弃过期结果
            var url = r.canvas.toDataURL('image/jpeg', 0.92);
            var pre = new Image();                // 预解码 dataURL，就绪后再淡入
            pre.onload = function () {
                if (seq !== previewSeq) return;
                posterCanvas = r.canvas;
                img.src = url;
                img.classList.add('on');
                if (wrap) { wrap.classList.remove('loading', 'error'); }
                if (!r.coverLoaded && data && data.cover) toast('封面图暂时无法加载，海报不含图片');
            };
            pre.onerror = function () { markPreviewError(seq); };
            pre.src = url;
        }).catch(function () { markPreviewError(seq); });
    }

    function setLang(lg) {
        lang = lg;
        $$('.slang').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-lang') === lg); });
        refreshPreview();
    }

    function openShare() {
        var modal = $('#share-modal');
        if (!modal || !data) return;
        modal.classList.add('on');
        document.body.style.overflow = 'hidden';
        // 默认语言跟随当前激活的提示词 tab
        var active = $('.prompt-tabs .ptab.on');
        var label = active ? active.textContent.trim() : '';
        var def = (label === 'English' || label === 'en') ? 'en'
            : (label === '中文' || label === 'zh') ? 'zh'
            : (pickPrompt(data, 'zh').lang === 'zh' ? 'zh' : 'en');
        setLang(def);
    }

    function closeShare() {
        var modal = $('#share-modal');
        if (!modal) return;
        modal.classList.remove('on');
        document.body.style.overflow = '';
        var box = $('#qr-box'); if (box) box.classList.remove('on');
    }

    // ------------------------------------------------------- 启动
    function boot() {
        var el = $('#share-data');
        if (!el) return;
        try { data = JSON.parse(el.textContent); } catch (e) { return; }

        var btn = $('#share-btn');
        if (btn) btn.addEventListener('click', openShare);
        var close = $('#share-close');
        if (close) close.addEventListener('click', closeShare);
        var mask = $('#share-modal');
        if (mask) mask.addEventListener('click', function (e) { if (e.target === mask) closeShare(); });

        $$('.slang').forEach(function (b) {
            b.addEventListener('click', function () { setLang(b.getAttribute('data-lang')); });
        });
        var b;
        if ((b = $('#poster-save'))) b.addEventListener('click', downloadPoster);
        if ((b = $('#poster-share-img'))) b.addEventListener('click', sharePosterImage);
        if ((b = $('#share-weibo'))) b.addEventListener('click', shareWeibo);
        if ((b = $('#share-qr'))) b.addEventListener('click', showQr);
        if ((b = $('#share-copy'))) b.addEventListener('click', copyLink);
        if ((b = $('#share-system'))) b.addEventListener('click', systemShare);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && $('#share-modal') && $('#share-modal').classList.contains('on')) closeShare();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
