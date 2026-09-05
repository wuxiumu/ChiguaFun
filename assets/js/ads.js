/**
 * OpenNana 提示词库 - 广告位（数据源 assets/data/ad_slots.json）
 *
 * 能力：
 *   - 全站右上角浮标（JS 渲染）：每个广告一条文字按钮，点击弹窗看图
 *   - 弹窗：展示广告图 + 文案；若配置 link_url 则显示"去看看"跳转按钮
 *   - 列表广告占位：在瀑布流中按间隔插入广告卡片（.ad-card），点击同样弹窗
 *
 * 配置项（ad_slots.json）：
 *   { enabled: true, items: [ { id, label, image, alt, link_url, mp_app_id, mp_path } ] }
 *   mp_app_id / mp_path 为小程序字段，Web 端忽略。
 */
(function () {
    'use strict';

    var ADS = null;
    var modal = null, modalImg = null, modalCap = null, modalJump = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ------------------------------------------------------- 弹窗
    function buildModal() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'ad-modal';
        modal.innerHTML =
            '<div class="ad-modal-box">' +
                '<button type="button" class="ad-modal-close" aria-label="关闭">×</button>' +
                '<img class="ad-modal-img" alt="">' +
                '<p class="ad-modal-cap"></p>' +
                '<a class="btn btn-primary ad-modal-jump" target="_blank" rel="noopener" style="display:none">去看看</a>' +
            '</div>';
        document.body.appendChild(modal);
        modalImg  = modal.querySelector('.ad-modal-img');
        modalCap  = modal.querySelector('.ad-modal-cap');
        modalJump = modal.querySelector('.ad-modal-jump');
        modal.querySelector('.ad-modal-close').addEventListener('click', closeAd);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeAd(); });
    }

    function openAd(it) {
        buildModal();
        modalImg.src = it.image || '';
        modalImg.alt = it.alt || it.label || '';
        modalCap.textContent = it.alt || it.label || '';
        if (it.link_url) {
            modalJump.href = it.link_url;
            modalJump.style.display = '';
        } else {
            modalJump.style.display = 'none';
            modalJump.removeAttribute('href');
        }
        modal.classList.add('on');
        document.body.style.overflow = 'hidden';
    }

    function closeAd() {
        if (!modal) return;
        modal.classList.remove('on');
        document.body.style.overflow = '';
    }

    // ------------------------------------------------------- 广告浮标（全站）
    // PC：固定右上角；移动端：插入导航(header)下方居中（CSS 响应式切换）。
    // 因此 DOM 上放在 header 之后，移动端 in-flow 居中、PC 用 fixed 定位到右上。
    function buildFloat() {
        if (document.getElementById('ad-float')) return;
        var w = document.createElement('div');
        w.id = 'ad-float';
        w.className = 'ad-float';
        ADS.items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ad-float-item';
            b.textContent = it.label || '广告';
            b.title = it.alt || it.label || '';
            b.addEventListener('click', function () { openAd(it); });
            w.appendChild(b);
        });
        var hdr = document.querySelector('header.site-head');
        if (hdr && hdr.parentNode) hdr.parentNode.insertBefore(w, hdr.nextSibling);
        else document.body.appendChild(w);
    }

    // 列表内广告占位（.ad-card）当前不启用；仅保留全站浮标 + 弹窗。
    // 如需恢复：在此按间隔向 .masonry 插入 Cards.adHtml(...) 并暴露 window.injectListAds。

    // ------------------------------------------------------- 启动
    function boot() {
        fetch('/assets/data/ad_slots.json', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || j.enabled !== true || !j.items || !j.items.length) return;
                ADS = j;
                buildFloat();
            })
            .catch(function () { /* 广告失败不影响站点 */ });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('on')) closeAd();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
