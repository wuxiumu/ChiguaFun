<?php
/**
 * OpenNana 提示词库 - PHP 入口（兼容模式）
 *
 * 行为：
 *   - 详情页：?p=<slug>            渲染（带 SEO meta + JSON-LD）
 *   - 调试页：?debug=1             渲染完整 PHP 版（保留旧版能力）
 *   - 其他：                       301 → /index.html（静态首页）
 *
 * 静态首页 /index.html 由 gen_static.php 生成；本文件仅作为详情入口和 debug 兼容层。
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$q       = trim((string)($_GET['q'] ?? ''));
$model   = trim((string)($_GET['model'] ?? ''));
$slug    = trim((string)($_GET['p'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$debug   = !empty($_GET['debug']);

// ---- 路由分发 ----
if ($slug !== '') {
    render_detail($slug, $q, $model);
    return;
}
if ($debug) {
    render_legacy($q, $model, $page);
    return;
}

// 其他列表/搜索/筛选形态都交给静态首页 + JS 处理
header('Location: /index.html', true, 301);
exit;

// ============================================================== 详情页
function render_detail(string $slug, string $q, string $model): void
{
    if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        $ae = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        if (strpos($ae, 'gzip') !== false) {
            ini_set('zlib.output_compression', 'On');
            ini_set('zlib.output_compression_level', '6');
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer-when-downgrade');
    header('Cache-Control: public, max-age=60, must-revalidate');

    if (!db_exists()) {
        render_empty_index();
        return;
    }

    $cur = get_item($slug);
    if (!$cur) {
        render_404($slug);
        return;
    }

    $prompts   = get_prompts((int)$cur['id']);
    $tags      = json_decode((string)$cur['tags'], true) ?: [];
    $images    = json_decode((string)$cur['images'], true) ?: [];
    $videos    = json_decode((string)($cur['videos'] ?? '[]'), true) ?: [];
    [$prev, $next] = get_neighbors($cur, $q, $model);

    $title    = (string)$cur['title'];
    $siteUrl  = site_origin();
    $pageUrl  = $siteUrl . '/index.php?p=' . rawurlencode($slug);
    $descr    = (string)$cur['descr'];
    if ($descr === '' && $prompts) {
        // 取首段提示词前 160 字做描述
        $descr = mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$prompts[0]['body'])), 0, 160);
    }
    $descr    = $descr !== '' ? $descr : $title . ' - AI ' . (string)$cur['model'] . ' 提示词案例';
    $cover    = $images ? thumb_url((int)$cur['id'], 1, 1200) : '';
    $coverAbs = abs_url($cover);   // cdn 模式已是绝对地址，local 模式补站点源
    $model    = (string)$cur['model'];

    render_seo_head([
        'title' => $title . ' · ' . $model . ' 提示词',
        'descr' => $descr,
        'url'   => $pageUrl,
        'image' => $coverAbs,
        'type'  => 'article',
        'item'  => $cur,
        'prompts' => $prompts,
        'images'  => $images,
    ]);

    ?>
    <div class="wrap">
        <div class="detail">
            <div class="detail-grid">
                <div class="detail-media">
                    <?php if ($images): ?>
                        <?php foreach ($images as $i => $im):
                            $u  = img_url((string)$im);                      // 原图（cdn 模式为 CDN 原图，local 为本地路径）
                            $tw = thumb_url((int)$cur['id'], $i + 1, 1200);   // 展示用
                        ?>
                        <span class="dimg-wrap">
                            <img class="dimg" src="<?= h($tw) ?>" data-full="<?= h($u) ?>"
                                 alt="<?= h($title) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                        </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="nopic">暂无图片</div>
                    <?php endif; ?>

                    <?php if ($videos): ?>
                        <div class="video-block">
                            <div class="sec-title">示例视频</div>
                            <?php foreach ($videos as $vu): ?>
                                <video controls preload="metadata" playsinline poster="<?= h($images ? thumb_url((int)$cur['id'], 1, 1200) : '') ?>">
                                    <source src="<?= h($vu) ?>" type="video/mp4">
                                    <p>浏览器不支持播放视频：<a href="<?= h($vu) ?>" target="_blank" rel="noopener">下载</a></p>
                                </video>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="detail-side">
                    <h1><?= h($title) ?></h1>

                    <dl class="kv">
                        <?php if ($model):    ?><dt>模型</dt><dd><?= h($model) ?></dd><?php endif; ?>
                        <?php if ($tags):     ?><dt>标签</dt><dd><?= h(implode('、', $tags)) ?></dd><?php endif; ?>
                        <?php if ($cur['src_name'] || $cur['src_url']): ?>
                            <dt>来源</dt><dd>
                            <?php if ($cur['src_url']): ?>
                                <a href="<?= h($cur['src_url']) ?>" target="_blank" rel="noopener"><?= h($cur['src_name'] ?: '查看原贴') ?></a>
                            <?php else: ?><?= h($cur['src_name']) ?><?php endif; ?>
                            </dd>
                        <?php endif; ?>
                        <?php if ($cur['reviewed_at']): ?><dt>收录</dt><dd><?= h(fmt_date($cur['reviewed_at'])) ?></dd><?php endif; ?>
                        <?php if ($cur['descr']):       ?><dt>简介</dt><dd><?= h($cur['descr']) ?></dd><?php endif; ?>
                    </dl>

                    <?php if ($prompts): ?>
                        <div class="sec-title">
                            <span>提示词</span>
                            <?php if (count($prompts) > 1): ?>
                                <button class="copy copy-all" data-all="1">一键复制全部</button>
                            <?php endif; ?>
                        </div>
                        <?php if (count($prompts) > 1): ?>
                            <div class="prompt-tabs" role="tablist">
                                <?php foreach ($prompts as $i => $p): ?>
                                    <button type="button" class="ptab<?= $i === 0 ? ' on' : '' ?>"
                                            data-tab="pt<?= (int)$p['seq'] ?>" role="tab"><?= h(prompt_lang_label((string)$p['label'])) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($prompts as $i => $p): ?>
                            <div class="prompt-block ppanel<?= $i === 0 ? ' on' : '' ?>" id="panel-pt<?= (int)$p['seq'] ?>">
                                <div class="prompt-head">
                                    <span class="ops">
                                        <span class="tag"><?= number_format((int)$p['chars']) ?> 字</span>
                                        <button class="copy" data-target="pt<?= (int)$p['seq'] ?>">复制</button>
                                    </span>
                                </div>
                                <pre id="pt<?= (int)$p['seq'] ?>"><?= h($p['body']) ?></pre>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">该条目没有提示词内容。</p>
                    <?php endif; ?>

                    <div class="nav-row">
                        <?php if ($prev): ?><a class="btn" href="<?= h('/index.php?p=' . rawurlencode($prev['slug']) . ($q || $model ? '&q=' . rawurlencode($q) . ($model ? '&model=' . rawurlencode($model) : '') : '')) ?>">← <?= h(mb_substr((string)$prev['title'], 0, 18)) ?></a><?php endif; ?>
                        <?php if ($next): ?><a class="btn" href="<?= h('/index.php?p=' . rawurlencode($next['slug']) . ($q || $model ? '&q=' . rawurlencode($q) . ($model ? '&model=' . rawurlencode($model) : '') : '')) ?>"><?= h(mb_substr((string)$next['title'], 0, 18)) ?> →</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <a class="back" href="/">← 返回图库</a>
        <p class="kbd-hint">
            <span>点击图片全屏（多图 <span class="kbd">←</span><span class="kbd">→</span> 切换 · <span class="kbd">Esc</span> 关闭）</span>
            <span><span class="kbd">←</span><span class="kbd">→</span> 上下条</span>
            <span><span class="kbd">C</span> 复制当前提示词</span>
            <span><span class="kbd">Esc</span> 返回列表</span>
        </p>
    </div>
    <?php
    render_foot();
}

// ============================================================== 调试页（保留旧版能力）
function render_legacy(string $q, string $model, int $page): void
{
    if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        $ae = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        if (strpos($ae, 'gzip') !== false) {
            ini_set('zlib.output_compression', 'On');
            ini_set('zlib.output_compression_level', '6');
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer-when-downgrade');
    header('Cache-Control: no-store');

    if (!db_exists()) {
        render_empty_index();
        return;
    }

    $t0     = microtime(true);
    $total  = count_items($q, $model);
    $pages  = max(1, (int)ceil($total / PER_PAGE));
    $page   = min($page, $pages);
    $rows   = query_items($q, $model, PER_PAGE, ($page - 1) * PER_PAGE);

    $models = model_counts();
    $base   = array_filter(['q' => $q, 'model' => $model]);

    ?>
    <!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>[DEBUG] OpenNana 提示词库 · 本地图库</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <?= watermark_style() ?>
    <?= analytics_scripts() ?>
    <link rel="icon" href="data:,">
    <script>(function(){try{var t=localStorage.getItem('theme');var s=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;document.documentElement.className=(t==='dark'||(!t&&s))?'dark':'';}catch(e){}})();</script>
    </head><body>
    <header class="site-head"><div class="inner">
        <a class="logo" href="/">[DEBUG] Open<span>Nana</span></a>
        <form class="search-bar" method="get" action="/index.html">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="搜索标题 / 提示词内容…" autocomplete="off">
            <input type="hidden" name="debug" value="1">
            <button type="submit">搜索</button>
        </form>
        <div class="stat-mini">PHP 调试视图 · 总 <?= number_format(total_items()) ?> 条</div>
        <button class="theme-toggle" id="theme-toggle" type="button" title="切换主题" aria-label="切换主题">
            <svg class="moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            <svg class="sun"  viewBox="0 0 24 24"><path d="M12 4V2m0 20v-2M4 12H2m20 0h-2M5.6 5.6 4.2 4.2m15.6 15.6-1.4-1.4M5.6 18.4l-1.4 1.4M19.8 4.2l-1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div></header>

    <div class="wrap">
        <div class="filters">
            <a class="chip <?= $model === '' ? 'on' : '' ?>" href="?<?= h(http_build_query(['q' => $q, 'debug' => 1])) ?>" data-model="">全部<span class="n"><?= number_format(total_items()) ?></span></a>
            <?php foreach ($models as $m => $n): ?>
                <a class="chip <?= $model === (string)$m ? 'on' : '' ?>" href="?<?= h(http_build_query(['q' => $q, 'model' => (string)$m, 'debug' => 1])) ?>" data-model="<?= h((string)$m) ?>">
                    <?= h((string)$m) ?><span class="n"><?= number_format($n) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($q || $model): ?>
            <p class="result-line">
                筛选出 <b><?= number_format($total) ?></b> 条
                <?php if ($q): ?>（关键词：<b><?= h($q) ?></b>）<?php endif; ?>
                <?php if ($model): ?>（模型：<b><?= h($model) ?></b>）<?php endif; ?>
                · <a href="/index.php?debug=1">清除筛选</a>
            </p>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="empty"><h3>没有匹配的内容</h3><p>换个关键词，或<a href="/index.php?debug=1">返回全部</a></p></div>
        <?php else: ?>
            <div class="masonry">
                <?php foreach ($rows as $r):
                    $id   = (int)$r['id'];
                    $link = '/index.php?p=' . urlencode($r['slug']) . ($base ? '&' . http_build_query($base + ['debug' => 1]) : '&debug=1');
                    $cw   = (int)$r['cover_w'];
                    $ch   = (int)$r['cover_h'];
                ?>
                    <a class="card" href="<?= h($link) ?>">
                        <span class="thumb"<?= $cw && $ch ? ' style="aspect-ratio:' . $cw . '/' . $ch . '"' : '' ?>>
                            <?php if ($r['cover']): ?>
                                <img src="<?= h(thumb_url($id, 1, 400)) ?>"
                                     width="<?= $cw ?: 400 ?>" height="<?= $ch ?: 400 ?>"
                                     alt="<?= h($r['title']) ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span class="ph">无图片</span>
                            <?php endif; ?>
                        </span>
                        <span class="body">
                            <span class="t"><?= h($r['title']) ?></span>
                            <span class="meta">
                                <?php if ($r['model']): ?><span class="tag model"><?= h($r['model']) ?></span><?php endif; ?>
                                <?php if ($r['media_type'] === 'video'): ?><span class="tag video">视频</span><?php endif; ?>
                                <span class="tag"><?= h(fmt_date($r['reviewed_at'])) ?></span>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pager">
                    <?php if ($page > 1): ?><a href="?<?= h(http_build_query($base + ['page' => $page - 1, 'debug' => 1])) ?>">上一页</a><?php endif; ?>
                    <?php
                    $from = max(1, $page - 2);
                    $to   = min($pages, $from + 4);
                    $from = max(1, $to - 4);
                    if ($from > 1) {
                        echo '<a href="?' . h(http_build_query($base + ['page' => 1, 'debug' => 1])) . '">1</a><span class="gap">…</span>';
                    }
                    for ($i = $from; $i <= $to; $i++):
                        echo $i === $page
                            ? '<span class="cur">' . $i . '</span>'
                            : '<a href="?' . h(http_build_query($base + ['page' => $i, 'debug' => 1])) . '">' . $i . '</a>';
                    endfor;
                    if ($to < $pages) {
                        echo '<span class="gap">…</span><a href="?' . h(http_build_query($base + ['page' => $pages, 'debug' => 1])) . '">' . $pages . '</a>';
                    }
                    ?>
                    <?php if ($page < $pages): ?><a href="?<?= h(http_build_query($base + ['page' => $page + 1, 'debug' => 1])) ?>">下一页</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="toast" id="toast"></div>
    <script src="/assets/js/app.js" defer></script>
    </body></html>
    <?php
}

// ============================================================== 通用

/** 提示词段落标签 → 简洁语言名（tab 用）。先剥掉 "1. " 前缀再精确匹配，避免 "scene" 误判成 English */
function prompt_lang_label(string $label): string
{
    $t   = trim(preg_replace('/^\s*\d+\s*[\.、\)\-]?\s*/u', '', $label));
    $low = strtolower($t);
    if (in_array($low, ['zh', 'cn', 'chinese', 'zh-cn'], true) || strpos($t, '中文') !== false) {
        return '中文';
    }
    if (in_array($low, ['en', 'eng', 'english', 'en-us'], true) || strpos($t, '英文') !== false) {
        return 'English';
    }
    return $t !== '' ? $t : '提示词';
}

function render_seo_head(array $info): void
{
    $site   = site_origin();
    $title  = $info['title'];
    $descr  = mb_substr((string)$info['descr'], 0, 200);
    $url    = (string)$info['url'];
    $img    = (string)$info['image'];
    $type   = (string)($info['type'] ?? 'article');
    $item   = (array)$info['item'];
    $prompts= (array)($info['prompts'] ?? []);
    $images = (array)($info['images'] ?? []);

    $tags   = json_decode((string)($item['tags'] ?? '[]'), true) ?: [];
    $model  = (string)($item['model'] ?? '');
    $reviewed = (string)($item['reviewed_at'] ?? '');
    $created  = (string)($item['created_at'] ?? '');

    $ld = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'         => 'WebSite',
                '@id'           => $site . '/#website',
                'url'           => $site . '/',
                'name'          => 'OpenNana 提示词库',
            ],
            [
                '@type'         => 'CreativeWork',
                '@id'           => $url . '#item',
                'url'           => $url,
                'name'          => (string)($item['title'] ?? ''),
                'description'   => $descr,
                'author'        => ['@type' => 'Person', 'name' => (string)($item['src_name'] ?? '')],
                'datePublished' => $created ? gmdate('Y-m-d\TH:i:s\Z', strtotime(substr($created, 0, 19))) : null,
                'dateModified'  => $reviewed ? gmdate('Y-m-d\TH:i:s\Z', strtotime(substr($reviewed, 0, 19))) : null,
                'keywords'      => implode(', ', array_merge([$model], $tags)),
                'image'         => $img,
                'inLanguage'    => 'zh-CN',
                'isPartOf'      => ['@id' => $site . '/#website'],
                'publisher'     => ['@type' => 'Organization', 'name' => 'OpenNana 提示词库', 'url' => $site . '/'],
            ],
        ],
    ];
    // 移除 null 字段
    array_walk_recursive($ld, function (&$v) { if ($v === null) $v = ''; });

    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($descr) ?>">
<meta name="keywords" content="<?= h(implode(',', array_merge([$model, 'AI 提示词'], $tags))) ?>">
<meta name="robots" content="index,follow,max-image-preview:large">
<meta name="theme-color" content="#0f1216" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
<link rel="canonical" href="<?= h($url) ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><text y="52" font-size="52">🍌</text></svg>') ?>">

<meta property="og:type" content="<?= h($type) ?>">
<meta property="og:site_name" content="OpenNana 提示词库">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($descr) ?>">
<meta property="og:url" content="<?= h($url) ?>">
<meta property="og:image" content="<?= h($img) ?>">
<meta property="og:locale" content="zh_CN">
<meta property="og:video" content="<?= $images && $item['videos'] ? h((string)json_decode((string)($item['videos'] ?? '[]'), true)[0]) : '' ?>">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($descr) ?>">
<meta name="twitter:image" content="<?= h($img) ?>">

<script>(function(){try{var t=localStorage.getItem('theme');var s=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;document.documentElement.className=(t==='dark'||(!t&&s))?'dark':'';}catch(e){}})();</script>
<link rel="stylesheet" href="/assets/css/style.css">
<?= watermark_style() ?>
<?= analytics_scripts() ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<header class="site-head"><div class="inner">
    <a class="logo" href="/">Open<span>Nana</span> 提示词库</a>
    <form class="search-bar" method="get" action="/index.html">
        <input type="text" name="q" placeholder="搜索标题 / 提示词内容…" autocomplete="off">
        <button type="submit">搜索</button>
    </form>
    <div class="stat-mini">已收录 <?= number_format(total_items()) ?> 条 · 图片 <?= number_format(image_file_count()) ?> 张</div>
    <button class="theme-toggle" id="theme-toggle" type="button" title="切换主题" aria-label="切换主题">
        <svg class="moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="sun"  viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4V2m0 20v-2M4 12H2m20 0h-2M5.6 5.6 4.2 4.2m15.6 15.6-1.4-1.4M5.6 18.4l-1.4 1.4M19.8 4.2l-1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
    </button>
</div></header>
<?php
}

function render_foot(): void
{
    ?>
<div class="toast" id="toast"></div>
<script src="/assets/js/app.js" defer></script>
</body></html>
<?php
}

function render_empty_index(): void
{
    ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
    <title>尚未生成索引</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    </head><body><div class="wrap"><div class="empty">
    <h3>索引还没生成</h3>
    <p>在 <code><?= h(__DIR__) ?></code> 下执行：</p>
    <pre>php build_index.php && php gen_static.php</pre>
    </div></div></body></html><?php
}

function render_404(string $slug): void
{
    http_response_code(404);
    ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
    <title>未找到 - OpenNana 提示词库</title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/assets/css/style.css">
    </head><body><div class="wrap"><div class="empty">
    <h3>没有找到这条提示词</h3>
    <p>slug: <code><?= h($slug) ?></code></p>
    <p><a href="/">← 返回图库</a></p>
    </div></div>
    <script src="/assets/js/app.js" defer></script>
    </body></html><?php
}