/**
 * OpenNana 提示词库 - 统一卡片模块（唯一卡片模板源）
 *
 * 首页列表 / 手气不错 / 详情页相关推荐 / 广告占位 全部用本模块渲染，
 * 保证全站卡片样式一致、便于统一维护。
 *
 * 卡片结构（.gcard）：方形缩略图 + 两行标题，与详情页相关推荐同款。
 * 服务端静态首屏（gen_static / gen_pages）输出同构 markup 以保证首屏与 SEO。
 *
 * 用法：
 *   Cards.html(item)        单个卡片 HTML 字符串
 *   Cards.grid(items)       多个卡片拼接
 *   item: { url, title, cover }   cover 为展示缩略图地址（为空显示占位）
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function html(it) {
        var cover = it.cover
            ? '<img src="' + esc(it.cover) + '" alt="' + esc(it.title) + '" loading="lazy" decoding="async">'
            : '<span class="ph">无图片</span>';
        return '<a class="gcard" href="' + esc(it.url) + '">' +
                   '<span class="gcard-thumb">' + cover + '</span>' +
                   '<span class="gcard-t">' + esc(it.title) + '</span>' +
               '</a>';
    }

    function grid(items) {
        return (items || []).map(html).join('');
    }

    window.Cards = { html: html, grid: grid, esc: esc };
})();
