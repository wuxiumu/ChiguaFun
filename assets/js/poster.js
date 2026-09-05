/**
 * OpenNana 提示词库 - 分享 & 竖版海报
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
    function loadImage(src) {
        return new Promise(function (resolve) {
            if (!src) { resolve(null); return; }
            var im = new Image();
            im.onload = function () { resolve(im); };
            im.onerror = function () { resolve(null); };
            im.src = src;
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
            var imgH = (iw && ih) ? Math.min(CONTENT_W * ih / iw, 720) : 0;

            var y = 40;                       // 顶部色条 10 + 间距
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

            // 背景 + 顶部色条
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = '#2563eb'; ctx.fillRect(0, 0, W, 10);

            // 封面图（圆角 + cover 裁切）
            if (imgEl && imgH > 0) {
                ctx.save();
                roundRectPath(ctx, PAD, 40, CONTENT_W, imgH, 16);
                ctx.clip();
                var scale = Math.max(CONTENT_W / iw, imgH / ih);
                var sw = CONTENT_W / scale, sh = imgH / scale;
                ctx.drawImage(imgEl, (iw - sw) / 2, (ih - sh) / 2, sw, sh, PAD, 40, CONTENT_W, imgH);
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
            ctx.fillText(d.site_name || 'OpenNana 提示词库', tx, footY + 8);
            ctx.fillStyle = '#6b7280'; ctx.font = '400 20px ' + FONT;
            var urlTxt = d.page_url.replace(/^https?:\/\//, '');
            while (ctx.measureText(urlTxt).width > CONTENT_W - 176 && urlTxt.length > 8) urlTxt = urlTxt.slice(0, -2);
            ctx.fillText(urlTxt, tx, footY + 52);
            ctx.fillText('长按 / 扫描二维码查看原文与完整提示词', tx, footY + 88);
            ctx.fillStyle = '#9aa3b2'; ctx.font = '400 18px ' + FONT;
            ctx.fillText('语言：' + (lg === 'zh' ? '中文' : 'English'), tx, footY + 122);

            return c;
        });
    }

    // ------------------------------------------------------- 分享动作
    function canvasToBlob(c) {
        return new Promise(function (res) { c.toBlob(res, 'image/jpeg', 0.92); });
    }

    function downloadPoster() {
        if (!posterCanvas) return;
        var a = document.createElement('a');
        a.href = posterCanvas.toDataURL('image/jpeg', 0.92);
        a.download = (data.slug || 'poster') + '-poster.jpg';
        document.body.appendChild(a); a.click(); a.remove();
        toast('海报已保存');
    }

    function sharePosterImage() {
        if (!posterCanvas) return;
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
                '&title=' + encodeURIComponent((data.title || '') + ' - OpenNana 提示词');
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
            navigator.share({ title: data.title, text: (data.title || '') + ' - OpenNana 提示词', url: data.page_url })
                .catch(function () {});
        } else {
            copyLink();
        }
    }

    // ------------------------------------------------------- 弹窗
    function refreshPreview() {
        var img = $('#poster-preview');
        buildPoster(data, lang).then(function (c) {
            posterCanvas = c;
            if (img) img.src = c.toDataURL('image/jpeg', 0.92);
        });
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
