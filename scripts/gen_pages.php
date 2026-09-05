<?php
/**
 * OpenNana 提示词库 - 详情页静态化
 *
 * 用法：
 *   php scripts/gen_pages.php            生成全部 p/<slug>.html
 *   php scripts/gen_pages.php --limit=N  只生成前 N 条（调试）
 *
 * 产物：./p/<slug>.html   每条目一个全静态详情页（SEO 友好、零 PHP 运行时）
 *
 * SEO 要点：
 *   - TDK：title=条目标题·模型；description=提示词正文截断（把提示词直接放进描述）；
 *     keywords=条目模型+标签+站点品牌词
 *   - canonical 自指静态地址；robots index,follow
 *   - 中英文：hreflang zh-CN / en / x-default（同页含双语提示词）+ og:locale alternate
 *   - JSON-LD CreativeWork（name/description/image/author/date/keywords/inLanguage）
 *   - OG / Twitter Card 完整
 *
 * 交互：静态页同样引入 app.js / qrcode.js / poster.js，保留提示词中英 tab、
 *       全屏看图、分享海报等能力（数据经内嵌 share-data JSON 提供）。
 */

declare(strict_types=1);
require __DIR__ . '/../lib.php';

$ROOT = dirname(__DIR__);
$OUT  = $ROOT . '/p';
if (!is_dir($OUT)) {
    @mkdir($OUT, 0755, true);
}

$limit = 0;
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int)$m[1];
    }
}

if (!db_exists()) {
    fwrite(STDERR, "索引未生成，先跑 php scripts/build_index.php\n");
    exit(1);
}

$site = site_origin();
$db   = db();
$st   = $db->query('SELECT * FROM items ORDER BY id');

$t0 = microtime(true);
$n  = 0;
while ($cur = $st->fetch(PDO::FETCH_ASSOC)) {
    if ($limit > 0 && $n >= $limit) {
        break;
    }
    $html = static_detail_html($cur, $site);
    if ($html === '') {
        continue;
    }
    @file_put_contents($OUT . '/' . $cur['slug'] . '.html', $html, LOCK_EX);
    $n++;
    if ($n % 2000 === 0) {
        printf("  …已生成 %d\r", $n);
    }
}
printf("[gen_pages] 生成 %d 个静态详情页 → p/ ，耗时 %.1fs\n", $n, microtime(true) - $t0);

// ---------------------------------------------------------------- 渲染

function static_detail_html(array $cur, string $site): string
{
    $slug    = (string)$cur['slug'];
    $title   = (string)$cur['title'];
    $model   = (string)$cur['model'];
    $pageUrl = $site . detail_url($slug);

    $prompts = get_prompts((int)$cur['id']);
    $tags    = json_decode((string)$cur['tags'], true) ?: [];
    $images  = json_decode((string)$cur['images'], true) ?: [];
    $videos  = json_decode((string)($cur['videos'] ?? '[]'), true) ?: [];
    [$prev, $next] = get_neighbors($cur, '', '');

    // 描述：把提示词正文直接放进 description（截断），无提示词才回退简介/标题
    $descSrc = '';
    if ($prompts) {
        $descSrc = preg_replace('/\s+/', ' ', strip_tags((string)$prompts[0]['body']));
    }
    if ($descSrc === '') {
        $descSrc = (string)$cur['descr'];
    }
    if ($descSrc === '') {
        $descSrc = $title . ' - AI ' . $model . ' 提示词案例';
    }
    $descr = mb_substr((string)$descSrc, 0, 200);

    $seoTitle = $title . ' · ' . ($model !== '' ? $model : 'AI') . ' 提示词';
    $kwList   = array_merge([$model !== '' ? $model : 'AI', 'AI 提示词'], array_map('strval', $tags), seo_keyword_list());
    $kw       = implode(',', array_values(array_filter($kwList)));

    $cover    = $images ? thumb_url((int)$cur['id'], 1, 1200) : '';
    $coverAbs = abs_url($cover);

    $reviewed = (string)$cur['reviewed_at'];
    $created  = (string)$cur['created_at'];

    // 分享数据
    $sharePrompts = [];
    foreach ($prompts as $p) {
        $lbl = prompt_lang_label((string)$p['label']);
        $lg  = $lbl === '中文' ? 'zh' : ($lbl === 'English' ? 'en' : 'p' . (int)$p['seq']);
        $sharePrompts[] = ['lang' => $lg, 'label' => $lbl, 'text' => (string)$p['body']];
    }
    $shareData = [
        'title'     => $title,
        'slug'      => $slug,
        'page_url'  => $pageUrl,
        'cover'     => $images ? img_url((string)$images[0]) : '',
        'model'     => $model,
        'site_name' => 'OpenNana 提示词库',
        'prompts'   => $sharePrompts,
    ];
    $related = related_items($cur, 16);   // 相关推荐：PC 16 个 4 列，移动端 CSS 只显示前 10

    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => 'CreativeWork',
        'name'     => $title,
        'description' => $descr,
        'url'      => $pageUrl,
        'image'    => $coverAbs,
        'author'   => ['@type' => 'Person', 'name' => (string)$cur['src_name']],
        'datePublished' => $created ? gmdate('Y-m-d\TH:i:s\Z', strtotime(substr($created, 0, 19))) : null,
        'dateModified'  => $reviewed ? gmdate('Y-m-d\TH:i:s\Z', strtotime(substr($reviewed, 0, 19))) : null,
        'keywords' => $kw,
        'inLanguage' => ['zh-CN', 'en'],
        'isPartOf' => ['@id' => $site . '/#website'],
    ];
    array_walk_recursive($ld, function (&$v) { if ($v === null) $v = ''; });

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($seoTitle) ?></title>
<meta name="description" content="<?= h($descr) ?>">
<meta name="keywords" content="<?= h($kw) ?>">
<meta name="robots" content="index,follow,max-image-preview:large">
<link rel="canonical" href="<?= h($pageUrl) ?>">
<link rel="alternate" hreflang="zh-CN" href="<?= h($pageUrl) ?>">
<link rel="alternate" hreflang="en" href="<?= h($pageUrl) ?>">
<link rel="alternate" hreflang="x-default" href="<?= h($pageUrl) ?>">
<meta name="theme-color" content="#0f1216" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><text y="52" font-size="52">🍌</text></svg>') ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="OpenNana 提示词库">
<meta property="og:title" content="<?= h($seoTitle) ?>">
<meta property="og:description" content="<?= h($descr) ?>">
<meta property="og:url" content="<?= h($pageUrl) ?>">
<meta property="og:image" content="<?= h($coverAbs) ?>">
<meta property="og:locale" content="zh_CN">
<meta property="og:locale:alternate" content="en_US">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($seoTitle) ?>">
<meta name="twitter:description" content="<?= h($descr) ?>">
<meta name="twitter:image" content="<?= h($coverAbs) ?>">
<script>(function(){try{var t=localStorage.getItem('theme');var s=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;document.documentElement.className=(t==='dark'||(!t&&s))?'dark':'';}catch(e){}})();</script>
<link rel="stylesheet" href="/assets/css/style.css">
<?= watermark_style() ?>
<?= analytics_scripts() ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<header class="site-head"><div class="inner">
    <a class="logo" href="/">Open<span>Nana</span> 提示词库</a>
    <form class="search-bar" method="get" action="/">
        <input type="text" name="q" placeholder="搜索标题 / 提示词内容…" autocomplete="off">
        <button type="submit">搜索</button>
    </form>
    <button type="button" class="btn random-btn" id="random-btn" title="随机看一批提示词">🎲 手气不错</button>
    <button class="theme-toggle" id="theme-toggle" type="button" title="切换主题" aria-label="切换主题">
        <svg class="moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="sun"  viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4V2m0 20v-2M4 12H2m20 0h-2M5.6 5.6 4.2 4.2m15.6 15.6-1.4 1.4M5.6 18.4l-1.4 1.4M19.8 4.2l-1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
    </button>
</div></header>
<div class="wrap">
    <div class="detail">
        <div class="detail-grid">
            <div class="detail-media">
                <?php if ($images): ?>
                    <?php foreach ($images as $i => $im):
                        $u  = img_url((string)$im);
                        $tw = thumb_url((int)$cur['id'], $i + 1, 1200);
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
                            <video controls preload="metadata" playsinline poster="<?= h($cover) ?>">
                                <source src="<?= h($vu) ?>" type="video/mp4">
                            </video>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="detail-side">
                <h1><?= h($title) ?></h1>
                <div class="detail-ops">
                    <button type="button" id="share-btn" class="btn btn-primary">📤 分享海报</button>
                </div>
                <dl class="kv">
                    <?php if ($model): ?><dt>模型</dt><dd><?= h($model) ?></dd><?php endif; ?>
                    <?php if ($tags): ?><dt>标签</dt><dd><?= h(implode('、', array_map('strval', $tags))) ?></dd><?php endif; ?>
                    <?php if ($cur['src_name'] || $cur['src_url']): ?>
                        <dt>来源</dt><dd>
                        <?php if ($cur['src_url']): ?>
                            <a href="<?= h($cur['src_url']) ?>" target="_blank" rel="noopener"><?= h($cur['src_name'] ?: '查看原贴') ?></a>
                        <?php else: ?><?= h($cur['src_name']) ?><?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <?php if ($reviewed): ?><dt>收录</dt><dd><?= h(fmt_date($reviewed)) ?></dd><?php endif; ?>
                    <?php if ($cur['descr']): ?><dt>简介</dt><dd><?= h($cur['descr']) ?></dd><?php endif; ?>
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
                    <?php if ($prev): ?><a class="btn" href="<?= h(detail_url((string)$prev['slug'])) ?>">← <?= h(mb_substr((string)$prev['title'], 0, 18)) ?></a><?php endif; ?>
                    <?php if ($next): ?><a class="btn" href="<?= h(detail_url((string)$next['slug'])) ?>"><?= h(mb_substr((string)$next['title'], 0, 18)) ?> →</a><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if ($related): ?>
    <div class="related">
        <div class="sec-title"><span>相关推荐</span></div>
        <div class="related-grid">
            <?php foreach ($related as $r): ?>
                <a class="rcard" href="<?= h(detail_url((string)$r['slug'])) ?>">
                    <span class="rthumb">
                        <?php if ($r['cover']): ?><img src="<?= h(thumb_url((int)$r['id'], 1, 400)) ?>" alt="<?= h((string)$r['title']) ?>" loading="lazy"><?php else: ?><span class="ph">无图片</span><?php endif; ?>
                    </span>
                    <span class="rt"><?= h((string)$r['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <a class="back" href="/">← 返回图库</a>
</div>
<footer class="site-foot"><div class="inner">
    <span class="foot-stats" id="traffic-stats"></span>
    <span class="foot-line">共 <?= number_format(total_items()) ?> 条 · <a href="/sitemap.xml">sitemap</a> · <a href="/">OpenNana 提示词库</a></span>
</div></footer>
<div class="share-modal" id="share-modal" role="dialog" aria-modal="true" aria-label="分享">
    <div class="share-box">
        <div class="share-head">
            <span class="share-title">分享</span>
            <div class="share-langs" role="group" aria-label="海报提示词语言">
                <button type="button" class="slang" data-lang="zh">中文</button>
                <button type="button" class="slang" data-lang="en">English</button>
            </div>
            <button type="button" class="share-close" id="share-close" aria-label="关闭">×</button>
        </div>
        <div class="share-body">
            <div class="poster-preview"><img id="poster-preview" alt="海报预览"></div>
            <div class="qr-box" id="qr-box"><img id="qr-img" alt="二维码"><p>扫一扫查看原文</p></div>
        </div>
        <div class="share-actions">
            <button type="button" class="btn btn-primary" id="poster-save">💾 保存海报</button>
            <button type="button" class="btn" id="poster-share-img">🖼️ 分享图片</button>
            <button type="button" class="btn" id="share-weibo">微博</button>
            <button type="button" class="btn" id="share-qr">二维码</button>
            <button type="button" class="btn" id="share-copy">复制链接</button>
            <button type="button" class="btn" id="share-system">系统分享</button>
        </div>
    </div>
</div>
<div class="toast" id="toast"></div>
<script type="application/json" id="share-data"><?= json_encode($shareData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="/assets/js/qrcode.js" defer></script>
<script src="/assets/js/poster.js" defer></script>
<script src="/assets/js/ads.js" defer></script>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
<?php
    return (string)ob_get_clean();
}
