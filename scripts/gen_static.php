<?php
/**
 * OpenNana 提示词库 - 静态化构建
 *
 * 用法：
 *   php gen_static.php           # 生成 index.html + sitemap.xml + robots.txt
 *   php gen_static.php --nuke    # 重新生成（清空后再写）
 *
 * 产物：
 *   ./index.html                 # 首页（含首批 36 条 + 完整 SEO）
 *   ./sitemap.xml                # 全站条目（sitemap 协议）
 *   ./robots.txt                 # 爬虫规则
 *   ./assets/                    # 已存在的 CSS/JS（不重写）
 *
 * 设计原则：
 *   - 单源：DB -> index.html。改完 build_index.php 必须再跑这个。
 *   - 域名可配：站点源取自 config（site_origin），SEO/canonical/sitemap 全部用它
 *   - 无三方：所有 meta / og / JSON-LD 都内联，不引外部资源
 *   - 错落兼容：生成失败不破坏现有 index.html（先写到 *.tmp 再 rename）
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

const FIRST_PAGE   = 36;
const SITEMAP_MAX  = 50000;   // 单文件上限，超过需分片

$ROOT = dirname(__DIR__);   // 项目根（脚本在 scripts/）

if (!db_exists()) {
    fwrite(STDERR, "索引未生成，先跑 php scripts/build_index.php\n");
    exit(1);
}

if (!is_dir($ROOT . '/assets/css') || !is_dir($ROOT . '/assets/js')) {
    fwrite(STDERR, "缺少 assets/css 或 assets/js 目录\n");
    exit(1);
}

$nuke = in_array('--nuke', $argv, true);

// ---------------------------------------------------------------- 数据
$t0     = microtime(true);
$total  = total_items();
$models = model_counts();
$imgs   = image_file_count();
$rows   = query_items('', '', FIRST_PAGE, 0);
$qtime  = (microtime(true) - $t0) * 1000;

// ---------------------------------------------------------------- 路径
$baseDir = $ROOT;
$htmlTmp = $baseDir . '/index.html.tmp';
$htmlOut = $baseDir . '/index.html';
$smTmp   = $baseDir . '/sitemap.xml.tmp';
$smOut   = $baseDir . '/sitemap.xml';

// ---------------------------------------------------------------- 生成
gen_index($htmlTmp, $total, $imgs, $models, $rows);
gen_sitemap($smTmp, $total, $models);
gen_robots($baseDir . '/robots.txt');

rename($htmlTmp, $htmlOut);
rename($smTmp, $smOut);

$dt = round((microtime(true) - $t0) * 1000);
$sz = filesize($htmlOut);
printf("[gen_static] index.html %s bytes (%.1f KB) · sitemap.xml · %d items · %.0fms\n",
    number_format($sz), $sz / 1024, $total, $dt);

// ---------------------------------------------------------------- 实现
function gen_index(string $path, int $total, int $imgs, array $models, array $rows): void
{
    $site   = site_origin();
    // TDK 可配置（config: seo_title / seo_description / seo_keywords，支持 {count} 占位）
    $tdk    = seo_tdk($total);
    $title  = $tdk['title'] !== '' ? $tdk['title'] : "OpenNana 提示词库 · {$total}+ 条 AI 提示词与生成案例";
    $desc   = $tdk['desc']  !== '' ? $tdk['desc']  : "收录 {$total}+ 条 ChatGPT、Nano Banana、Seedance、Grok、即梦 等模型的 AI 图像与视频提示词，含原图与中英文版本，可一键复制。";
    $kw     = $tdk['keywords'];
    $cover  = pick_cover($rows);
    $pages  = max(1, (int)ceil($total / FIRST_PAGE));

    // 头部 meta
    $h = [];
    $h[] = "<!DOCTYPE html>";
    $h[] = '<html lang="zh-CN">';
    $h[] = '<head>';
    $h[] = '<meta charset="utf-8">';
    $h[] = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    $h[] = '<title>' . h($title) . '</title>';
    $h[] = '<meta name="description" content="' . h($desc) . '">';
    $h[] = '<meta name="keywords" content="' . h($kw) . '">';
    $h[] = '<meta name="robots" content="index,follow,max-image-preview:large">';
    $h[] = '<meta name="theme-color" content="#0f1216" media="(prefers-color-scheme: dark)">';
    $h[] = '<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">';
    $h[] = '<meta name="generator" content="opennana-static">';
    $h[] = '<link rel="canonical" href="' . $site . '/">';
    $h[] = '<link rel="alternate" type="application/rss+xml" title="OpenNana 提示词库" href="' . $site . '/rss.xml">';
    $h[] = '<link rel="sitemap" type="application/xml" href="' . $site . '/sitemap.xml">';
    $h[] = '<link rel="icon" href="data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><text y="52" font-size="52">🍌</text></svg>') . '">';

    // Open Graph
    $h[] = '<meta property="og:type" content="website">';
    $h[] = '<meta property="og:site_name" content="OpenNana 提示词库">';
    $h[] = '<meta property="og:title" content="' . h($title) . '">';
    $h[] = '<meta property="og:description" content="' . h($desc) . '">';
    $h[] = '<meta property="og:url" content="' . $site . '/">';
    $h[] = '<meta property="og:image" content="' . h($cover) . '">';
    $h[] = '<meta property="og:locale" content="zh_CN">';

    // Twitter
    $h[] = '<meta name="twitter:card" content="summary_large_image">';
    $h[] = '<meta name="twitter:title" content="' . h($title) . '">';
    $h[] = '<meta name="twitter:description" content="' . h($desc) . '">';
    $h[] = '<meta name="twitter:image" content="' . h($cover) . '">';

    // 早期主题闪烁抑制（必须在 CSS 之前，且要内联以保证无三方）
    $h[] = '<script>(function(){try{var t=localStorage.getItem("theme");var s=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;document.documentElement.className=(t==="dark"||(!t&&s))?"dark":"";}catch(e){}})();</script>';

    // 资源
    $h[] = '<link rel="stylesheet" href="/assets/css/style.css">';
    $h[] = watermark_style();     // 注入 --wm-text（图片水印文案，可配置）
    $h[] = analytics_scripts();   // 第三方统计（Clarity + 51.la，ID 来自 config）

    // JSON-LD: WebSite + Organization + ItemList
    $itemsJson = [];
    foreach ($rows as $r) {
        $itemsJson[] = [
            '@type'    => 'ListItem',
            'position' => (int)$r['id'],
            'url'      => $site . detail_url((string)$r['slug']),
            'name'     => (string)$r['title'],
        ];
    }
    $ld = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'         => 'WebSite',
                '@id'           => $site . '/#website',
                'url'           => $site . '/',
                'name'          => 'OpenNana 提示词库',
                'description'   => $desc,
                'inLanguage'    => 'zh-CN',
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => $site . '/?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type'       => 'Organization',
                '@id'         => $site . '/#org',
                'name'        => 'OpenNana 提示词库',
                'url'         => $site . '/',
                'logo'        => $site . '/assets/img/logo.svg',
            ],
            [
                '@type'           => 'ItemList',
                '@id'             => $site . '/#itemlist',
                'name'            => 'AI 提示词列表（首屏）',
                'numberOfItems'   => count($itemsJson),
                'itemListElement' => $itemsJson,
            ],
        ],
    ];
    $h[] = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

    $h[] = '</head>';
    $h[] = '<body>';
    $h[] = '<header class="site-head"><div class="inner">';
    $h[] = '<a class="logo" href="/">Open<span>Nana</span> 提示词库</a>';
    $h[] = '<form class="search-bar" method="get" action="/index.html">';
    $h[] = '<input type="text" name="q" placeholder="搜索标题 / 提示词内容…" autocomplete="off">';
    $h[] = '<button type="submit">搜索</button>';
    $h[] = '</form>';
    $h[] = '<button type="button" class="btn random-btn" id="random-btn" title="随机看一批提示词">🎲 手气不错</button>';
    $h[] = '<div class="stat-mini">已收录 ' . number_format($total) . ' 条 · 图片 ' . number_format($imgs) . ' 张</div>';
    $h[] = '<button class="theme-toggle" id="theme-toggle" type="button" title="切换主题" aria-label="切换主题">';
    $h[] = '<svg class="moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    $h[] = '<svg class="sun"  viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4V2m0 20v-2M4 12H2m20 0h-2M5.6 5.6 4.2 4.2m15.6 15.6-1.4-1.4M5.6 18.4l-1.4 1.4M19.8 4.2l-1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    $h[] = '</button>';
    $h[] = '</div></header>';

    // 主体
    $h[] = '<div class="wrap">';
    $h[] = '<div class="filters" id="filters">';
    $h[] = '<a class="chip on" href="/" data-model="">全部<span class="n">' . number_format($total) . '</span></a>';
    foreach ($models as $m => $n) {
        $h[] = '<a class="chip" href="' . h('/?model=' . rawurlencode((string)$m)) . '" data-model="' . h((string)$m) . '">'
             . h((string)$m) . '<span class="n">' . number_format($n) . '</span></a>';
    }
    $h[] = '</div>';

    $h[] = '<div id="main">';
    $h[] = '<div class="masonry">';
    foreach ($rows as $r) {
        $h[] = card_static($r);
    }
    $h[] = '</div>';

    if ($pages > 1) {
        $h[] = '<nav class="pager">';
        for ($i = 1; $i <= min(5, $pages); $i++) {
            if ($i === 1) {
                $h[] = '<span class="cur">1</span>';
            } else {
                $h[] = '<a href="/index.html?page=' . $i . '" data-page="' . $i . '">' . $i . '</a>';
            }
        }
        if ($pages > 5) {
            $h[] = '<span class="gap">…</span>';
            $h[] = '<a href="/index.html?page=' . $pages . '" data-page="' . $pages . '">' . $pages . '</a>';
        }
        $h[] = '<a href="/index.html?page=2" data-page="2">下一页</a>';
        $h[] = '</nav>';
    }

    $h[] = '</div>'; // #main
    $h[] = '</div>'; // .wrap

    // footer（文案居中；流量统计由 app.js 拉取 /api/tj.php 填充，Redis 不可用则留空隐藏）
    $h[] = '<footer class="site-foot"><div class="inner">';
    $h[] = '<span class="foot-stats" id="traffic-stats"></span>';
    $h[] = '<span class="foot-line">共 ' . number_format($total) . ' 条 · ' . number_format($imgs) . ' 张图 · '
         . '<a href="/sitemap.xml">sitemap</a> · <a href="/index.php?debug=1">debug</a> · '
         . '<a href="https://github.com/wuxiumu/ChiguaFun" target="_blank" rel="noopener">OpenNana 提示词库</a></span>';
    $h[] = '</div></footer>';

    $h[] = '<div class="toast" id="toast"></div>';
    $h[] = '<script src="/assets/js/app.js" defer></script>';
    $h[] = '</body></html>';

    file_put_contents($path, implode("\n", $h));
}

function card_static(array $r): string
{
    $id    = (int)$r['id'];
    $slug  = (string)$r['slug'];
    $title = (string)$r['title'];
    $model = (string)$r['model'];
    $cw    = (int)$r['cover_w'];
    $ch    = (int)$r['cover_h'];
    $date  = (string)$r['reviewed_at'];
    $url   = detail_url($slug);
    $thumb = $r['cover'] ? thumb_url($id, 1, 400) : '';
    $isVid = ((string)($r['media_type'] ?: 'image')) === 'video';

    $ratio = ($cw && $ch) ? ' style="aspect-ratio:' . $cw . '/' . $ch . '"' : '';
    $img = $thumb
        ? '<img src="' . h($thumb) . '" width="' . ($cw ?: 400) . '" height="' . ($ch ?: 400) .
          '" alt="' . h($title) . '" loading="lazy" decoding="async">'
        : '<span class="ph">无图片</span>';
    $tags  = $model ? '<span class="tag model">' . h($model) . '</span>' : '';
    $video = $isVid ? '<span class="tag video">视频</span>' : '';
    $m     = fmt_date($date);

    return '<a class="card" href="' . h($url) . '">'
         . '<span class="thumb"' . $ratio . '>' . $img . '</span>'
         . '<span class="body">'
         . '<span class="t">' . h($title) . '</span>'
         . '<span class="meta">' . $tags . $video
         . '<span class="tag">' . h($m) . '</span>'
         . '</span>'
         . '</span>'
         . '</a>';
}

function pick_cover(array $rows): string
{
    foreach ($rows as $r) {
        if (!empty($r['cover'])) {
            return abs_url(thumb_url((int)$r['id'], 1, 1200));
        }
    }
    return abs_url('/assets/img/logo.svg');   // 兜底：库为空时用站点 logo
}

function gen_sitemap(string $path, int $total, array $models): void
{
    $site = site_origin();
    $pages = max(1, (int)ceil($total / FIRST_PAGE));

    $urls = [];
    $urls[] = [
        'loc' => $site . '/',
        'pri' => '1.0',
        'cf'  => 'daily',
    ];
    // 不把所有分页都列在 sitemap 里；分页通过内部链接可达，避免单 sitemap 过大
    for ($i = 2; $i <= min($pages, 20); $i++) {
        $urls[] = [
            'loc' => $site . '/?page=' . $i,
            'pri' => '0.5',
            'cf'  => 'weekly',
        ];
    }

    // 模型筛选页
    foreach ($models as $m => $_) {
        $urls[] = [
            'loc' => $site . '/?model=' . rawurlencode((string)$m),
            'pri' => '0.7',
            'cf'  => 'daily',
        ];
    }

    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $out .= "  <url>\n";
        $out .= '    <loc>' . h($u['loc']) . "</loc>\n";
        $out .= '    <changefreq>' . h($u['cf']) . "</changefreq>\n";
        $out .= '    <priority>' . h($u['pri']) . "</priority>\n";
        $out .= "  </url>\n";
    }
    $out .= "</urlset>\n";

    // 单独写详情条目（量大）
    // 写到 sitemap-items.xml，避免单个 sitemap 超 5 万条
    $itemsPath = dirname($path) . '/sitemap-items.xml';
    $itemsTmp  = $itemsPath . '.tmp';
    if (file_exists($itemsTmp)) unlink($itemsTmp);

    $fh = fopen($itemsTmp, 'w');
    fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
    fwrite($fh, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

    $db = db();
    $st = $db->query('SELECT slug, reviewed_at FROM items ORDER BY id');
    $now = gmdate('Y-m-d');
    $count = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $lm = $r['reviewed_at'] ? gmdate('Y-m-d', strtotime(substr((string)$r['reviewed_at'], 0, 10) ?: 'now')) : $now;
        $url = $site . detail_url((string)$r['slug']);
        fwrite($fh, "  <url>\n    <loc>" . h($url) . "</loc>\n    <lastmod>" . h($lm) . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.8</priority>\n  </url>\n");
        $count++;
    }
    fwrite($fh, "</urlset>\n");
    fclose($fh);
    rename($itemsTmp, $itemsPath);

    file_put_contents($path, $out);
}

function gen_robots(string $path): void
{
    $site = site_origin();
    $txt = <<<ROBOTS
User-agent: *
Allow: /
Disallow: /api/
Disallow: /cache/
Disallow: /data/
Disallow: /?debug
Sitemap: {$site}/sitemap.xml
Sitemap: {$site}/sitemap-items.xml

ROBOTS;
    file_put_contents($path, $txt);
}