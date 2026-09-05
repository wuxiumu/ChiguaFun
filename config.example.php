<?php
/**
 * OpenNana 提示词库 - 配置模板
 *
 * 用法：
 *   cp config.example.php config.php   # 然后按需修改 config.php
 *
 * config.php 不提交到 git（已在 .gitignore）。
 * 不创建 config.php 也能跑：所有键都有内置默认值（image_mode=cdn，开箱即用）。
 */

return [
    // 站点对外的源，用于 SEO canonical / og:url / sitemap / 绝对图片地址。
    // 留空时：Web 请求自动用当前 Host，CLI（如 gen_static.php）回退到 http://localhost:8765。
    // 生产环境务必填你的真实域名，否则 sitemap / og 里的链接不对。
    'site_origin' => 'https://your-domain.com',

    // 图片模式：
    //   'cdn'   —— 直接用 md frontmatter 里的 source_images（CDN 原图），无需下载图片，零磁盘占用（推荐）
    //   'local' —— 用本地 images/ + 服务端缩略图 thumbs/（需先 scrape.py 下载图片 + make_thumbs.php 预生成）
    'image_mode'  => 'cdn',

    // 自定义图片 CDN / 反代（仅 cdn 模式生效，可选）：
    // 把 source_images 里的 cdn_replace 前缀替换成 cdn_base，用于走自己的图床或反向代理。
    // 两者都留空则直接用原始 CDN 地址。
    //   例：'cdn_replace' => 'https://img.opennana.com', 'cdn_base' => 'https://cdn.your-domain.com'
    'cdn_replace' => '',
    'cdn_base'    => '',

    // 数据包 CDN（可选）：一个 data.tar.gz 的直链，`make data` 会下载并解压到 data/。
    // 用于不跑爬虫、直接复用你托管在 CDN 上的数据集。留空则只能通过 scrape.py 抓取。
    'data_cdn'    => '',

    // API 跨域：允许浏览器跨域调用 /api/*。默认 '*'（开源演示方便）；
    // 生产可收紧为你的域名，如 'https://your-domain.com'。设为 '' 则不下发 CORS 头（同源）。
    'cors_origin' => '*',
];
