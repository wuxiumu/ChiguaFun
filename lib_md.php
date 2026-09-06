<?php
/**
 * md 解析层：从 data/*.md 里取出 frontmatter 元数据、图片引用、提示词正文。
 * 不依赖数据库，可直接被抓取后的脚本复用。
 */

declare(strict_types=1);

const DATA_DIR_MD  = __DIR__ . '/data';
const IMAGE_DIR_MD = __DIR__ . '/images';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ------------------------------------------------------------------ 配置

/**
 * 读取配置：优先 config.php，缺失时回退内置默认值（新克隆开箱即用）。
 * config() 取全部；config('image_mode') 取单键。
 */
function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $defaults = [
            'site_origin'    => '',
            'image_mode'     => 'cdn',
            'cdn_base'       => '',
            'cdn_replace'    => '',
            // 阿里云 OSS/CDN：资源根（含 images/、thumbs/、data/ 的父路径），无尾斜杠
            'oss_base'       => '',
            // 是否追加 ?x-oss-process=…（OSS 图片处理 / CDN 图片处理）
            'oss_process'    => true,
            // 输出格式：webp（推荐）| jpg | png | ''（保持原格式）
            'oss_format'     => 'webp',
            // 压缩质量 1–100；0 表示不追加 quality
            'oss_quality'    => 75,
            'data_cdn'       => '',
            'cors_origin'    => '*',
            'watermark_text' => 'AI 生成',
            'clarity_id'     => '',
            'la51_id'        => '',
            // SEO TDK（Title/Description/Keywords），{count} 会被替换为收录总数
            'seo_title'       => '51chigua 吃瓜提示词库 · {count}+ 条 AI 提示词与生成案例',
            'seo_description' => '51chigua 吃瓜提示词库（🍉 ChiguaNana）收录 {count}+ 条 ChatGPT、Nano Banana、Seedance、Grok、即梦等模型的 AI 图像与视频提示词，含原图与中英文版本，可一键复制、生成分享海报。提示词吃瓜、banana我要吃瓜，每日更新。',
            'seo_keywords'    => '51chigua,吃瓜,提示词吃瓜,nana51chigua,banana51chigua,banana我要吃瓜,51吃瓜,吃瓜网,吃瓜群众,每日吃瓜,吃瓜爆料,AI吃瓜,吃瓜提示词,ChiguaNana,chiguanana 提示词,nano banana 提示词,nano banana prompt,banana 提示词,AI 提示词库,提示词大全,AI 绘画提示词,AI 视频提示词,ChatGPT 提示词,提示词分享,提示词画廊,prompt gallery',
        ];
        $file = __DIR__ . '/config.php';
        $user = is_file($file) ? (array)@include $file : [];
        $cfg  = array_merge($defaults, $user);
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/**
 * 站点展示名（分享海报 / OG / JSON-LD 等纯文本场景）。
 * 🍉 = 吃瓜彩蛋，ChiguaNana = 吃瓜 × Nano Banana。
 */
function site_brand(): string
{
    return '🍉 ChiguaNana 提示词库';
}

/** 顶栏 logo HTML（Nana 高亮） */
function site_logo_html(): string
{
    return '🍉 Chigua<span>Nana</span> 提示词库';
}

/**
 * 站点源：config.site_origin 优先，其次按请求 Host 自动探测，CLI 最后回退 localhost。
 * 始终以无尾斜杠返回。生产请在 config.php 填写真实域名（如 https://banana.chiguashentan.com）。
 */
function site_origin(): string
{
    $o = rtrim((string)config('site_origin'), '/');
    if ($o !== '') {
        return $o;
    }
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    // 仅本地 CLI 兜底；部署后务必配置 site_origin，否则 sitemap/og 会写成 localhost
    return 'http://localhost:8765';
}

/** 相对 URL -> 绝对 URL；已是 http(s) 绝对地址则原样返回。空串返回空串。 */
function abs_url(string $u): string
{
    if ($u === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    return site_origin() . '/' . ltrim($u, '/');
}

/**
 * 输出水印 CSS 变量的 <style>，注入到各页 <head>。
 * CSS 里 .wm 容器 ::after 用 content: var(--wm-text) 叠加白色水印。
 * watermark_text 为空时注入空串 → content:"" → 水印不可见（即关闭）。
 */
function watermark_style(): string
{
    $json = json_encode((string)config('watermark_text'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '<style>:root{--wm-text:' . $json . '}</style>';
}

/**
 * 输出第三方统计脚本（Microsoft Clarity + 51.la）。
 * ID 来自 config（clarity_id / la51_id）；未配置则不输出任何内容。
 *
 * 开源仓库考虑：ID 不写死在提交进 git 的模板里，避免克隆者部署后
 * 其访客数据误报进作者的统计账号。各自的 config.php（不进库）填自己的 ID。
 *
 * 51.la 官方片段外层是 document.write("<script>…</script>")；这里直接内联其
 * 等价的内部脚本（同样异步加载 sdk.51.la/js-sdk-pro.min.js），规避 document.write
 * 在慢网/异步场景下被浏览器拦截的弃用问题。
 */
function analytics_scripts(): string
{
    $out = '';

    $clarity = trim((string)config('clarity_id'));
    if ($clarity !== '') {
        $cid = json_encode($clarity, JSON_UNESCAPED_SLASHES);
        $out .= '<script type="text/javascript">'
              . '(function(c,l,a,r,i,t,y){'
              . 'c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};'
              . 't=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;'
              . 'y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);'
              . '})(window, document, "clarity", "script", ' . $cid . ');'
              . '</script>';
    }

    $la = trim((string)config('la51_id'));
    if ($la !== '') {
        $id = json_encode($la, JSON_UNESCAPED_SLASHES);
        $out .= '<script>!function(p){"use strict";!function(t){var s=window,e=document,i=p,'
              . 'c="".concat("https:"===e.location.protocol?"https://":"http://","sdk.51.la/js-sdk-pro.min.js"),'
              . 'n=e.createElement("script"),r=e.getElementsByTagName("script")[0];'
              . 'n.type="text/javascript",n.setAttribute("charset","UTF-8"),n.async=!0,n.src=c,n.id="LA_COLLECT",i.d=n;'
              . 'var o=function(){s.LA.ids.push(i)};'
              . 's.LA?s.LA.ids&&o():(s.LA=p,s.LA.ids=[],o()),r.parentNode.insertBefore(n,r)}()}'
              . '({id:' . $id . ',ck:' . $id . '});</script>';
    }

    return $out;
}

/**
 * 站点级 SEO TDK（Title / Description / Keywords），来自 config。
 * {count} 占位符替换为收录总数（传 0 则移除占位符）。
 *
 * @return array{title:string,desc:string,keywords:string}
 */
function seo_tdk(int $count = 0): array
{
    $rep = $count > 0 ? number_format($count) : '';
    $t = str_replace('{count}', $rep, (string)config('seo_title'));
    $d = str_replace('{count}', $rep, (string)config('seo_description'));
    $k = (string)config('seo_keywords');
    return ['title' => $t, 'desc' => $d, 'keywords' => $k];
}

/** 把 config 的 seo_keywords 拆成数组（去空），供详情页关键词合并品牌词。 */
function seo_keyword_list(): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string)config('seo_keywords')))));
}

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

/** 详情页静态地址：/p/<slug>.html（由 scripts/gen_pages.php 生成；缺失时 router 回退动态） */
function detail_url(string $slug): string
{
    return '/p/' . $slug . '.html';
}

/** 极简 YAML 子集解析：标量 / 数字 / 布尔 / JSON 数组 */
function parse_simple_yaml(string $txt): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $txt) as $line) {
        if (!preg_match('/^([A-Za-z0-9_]+):[ \t]*(.*)$/', $line, $m)) {
            continue;
        }
        $k = $m[1];
        $v = trim($m[2]);
        if ($v === '' || $v === 'null') {
            $out[$k] = null;
            continue;
        }
        $first = $v[0];
        if ($first === '"' || $first === '[') {
            $d = json_decode($v, true);
            $out[$k] = json_last_error() === JSON_ERROR_NONE ? $d : trim($v, '"');
        } elseif ($v === 'true' || $v === 'false') {
            $out[$k] = $v === 'true';
        } elseif (is_numeric($v)) {
            $out[$k] = $v + 0;
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** 已知的一级 section 名称（用于切分 body，避免把代码块里的 ## 标题误判为边界） */
const MD_SECTIONS = '(?:提示词|图片|视频|备注|示例|内容参考|参考|相关|来源)';

/** 从正文里抠出图片引用和提示词块 */
function parse_md_body(string $body): array
{
    $images = [];
    if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $body, $m)) {
        $images = $m[2]; // 取 URL（第 2 组）
    }

    // 按已知 section 切分；提示词内部的 "## 架构" 等二级标题不会命中这里
    $sections = preg_split(
        '/^(?=##[ \t]+' . MD_SECTIONS . '[ \t]*$)/m',
        $body,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $promptSection = '';
    foreach ($sections as $sec) {
        if (preg_match('/^##[ \t]*提示词[ \t]*\r?\n/', $sec)) {
            $promptSection = preg_replace('/^##[ \t]*提示词[ \t]*\r?\n/', '', $sec, 1);
            break;
        }
    }

    $prompts = [];
    if ($promptSection !== '') {
        $lines = preg_split('/\r?\n/', $promptSection);
        $n = count($lines);
        $label = '提示词';
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            // 提示词段落标签：### 1. 英文 / ### 1. zh 等
            if (preg_match('/^###[ \t]+(.+?)[ \t]*$/', $line, $lm)) {
                $label = trim($lm[1]) ?: '提示词';
                $i++;
                continue;
            }
            // 代码块开始（外层围栏）
            if (preg_match('/^```/', $line)) {
                $i++;
                $depth = 1;
                $buf = [];
                while ($i < $n && $depth > 0) {
                    $l = $lines[$i];
                    // 外层代码块中遇到下一个 prompt 标签（后面有空行+新代码块）：结束当前 prompt
                    if ($depth === 1 && preg_match('/^###[ \t]+(.+?)[ \t]*$/', $l, $lm)) {
                        $j = $i + 1;
                        while ($j < $n && $lines[$j] === '') {
                            $j++;
                        }
                        if ($j < $n && preg_match('/^```/', $lines[$j])) {
                            break;
                        }
                    }
                    if (preg_match('/^```[ \t]*$/', $l)) {
                        $depth--;
                        // 外层/内层围栏结束标记都不写入正文
                    } elseif (preg_match('/^```/', $l)) {
                        $depth++;
                        // 内层围栏开始标记也不写入正文，只保留其中的文本
                    } else {
                        $buf[] = $l;
                    }
                    $i++;
                }
                $prompts[] = ['label' => $label, 'text' => rtrim(implode("\n", $buf))];
                continue;
            }
            $i++;
        }
    }
    return ['images' => $images, 'prompts' => $prompts];
}

function parse_md_file(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $meta = [];
    $body = $raw;
    if (preg_match('/\A---[ \t]*\r?\n(.*?)\r?\n---[ \t]*\r?\n?(.*)\z/s', $raw, $m)) {
        $meta = parse_simple_yaml($m[1]);
        $body = $m[2];
    }
    $p = parse_md_body($body);

    return [
        'id'       => (int)($meta['id'] ?? 0),
        'slug'     => (string)($meta['slug'] ?? ''),
        'title'    => (string)($meta['title'] ?? basename($path, '.md')),
        'desc'     => (string)($meta['description'] ?? ''),
        'model'    => (string)($meta['model'] ?? ''),
        'type'     => (string)($meta['media_type'] ?? 'image'),
        'srcName'  => (string)($meta['source_name'] ?? ''),
        'srcUrl'   => (string)($meta['source_url'] ?? ''),
        'tags'     => (array)($meta['tags'] ?? []),
        'url'      => (string)($meta['url'] ?? ''),
        'views'    => (int)($meta['view_count'] ?? 0),
        'likes'    => (int)($meta['like_count'] ?? 0),
        'copies'   => (int)($meta['copy_count'] ?? 0),
        'created'  => (string)($meta['created_at'] ?? ''),
        'reviewed' => (string)($meta['reviewed_at'] ?? ''),
        'images'   => $p['images'],                              // 正文 ![](...) 引用（local 模式为本地路径）
        'source_images' => array_values((array)($meta['source_images'] ?? [])), // frontmatter 里的 CDN 原图
        'videos'   => (array)($meta['video_urls'] ?? []),
        'prompts'  => $p['prompts'],
    ];
}

/** 站点内相对路径 → 根路径（避免 /p/xxx.html 下解析成 /p/thumbs/...） */
function web_path(string $rel): string
{
    $rel = str_replace('\\', '/', trim($rel));
    if ($rel === '' || preg_match('#^https?://#i', $rel) || str_starts_with($rel, '//') || str_starts_with($rel, 'data:')) {
        return $rel;
    }
    return '/' . ltrim($rel, '/');
}

/**
 * 本地开发主机（localhost / 127.0.0.1）：默认走动态 PHP，方便预览，无需重生静态页。
 */
function is_local_dev(): bool
{
    if (PHP_SAPI === 'cli-server' || PHP_SAPI === 'apache2handler' || PHP_SAPI === 'fpm-fcgi' || PHP_SAPI === 'cgi-fcgi' || isset($_SERVER['HTTP_HOST'])) {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
    return false;
}

/** data/ 里记的相对路径 -> 可直接用于 <img> 的 URL（OSS 模式拼 oss_base，可带轻量压缩） */
function img_url(string $p): string
{
    $p = str_replace('\\', '/', $p);
    if (preg_match('#^https?://#i', $p)) {
        return media_url($p, 0);
    }
    $rel = ltrim(preg_replace('#^(\./|\.\./)+#', '', $p), '/');
    return media_url($rel, 0);
}

/**
 * 是否走阿里云 OSS/CDN 资源（仅 image_mode=oss）。
 * oss 模式索引侧按本地相对路径入库，展示侧拼 oss_base + 可选图片处理。
 */
function use_oss_media(): bool
{
    return (string)config('image_mode') === 'oss'
        && rtrim((string)config('oss_base'), '/') !== '';
}

/** 相对路径或绝对 URL → 对外可访问地址（相对路径在 OSS 模式下拼 oss_base） */
function media_abs(string $src): string
{
    $src = str_replace('\\', '/', trim($src));
    if ($src === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $src)) {
        return cdn_url($src);
    }
    $rel = ltrim(preg_replace('#^(\./|\.\./)+#', '', $src), '/');
    $base = rtrim((string)config('oss_base'), '/');
    if ($base !== '' && use_oss_media()) {
        return $base . '/' . $rel;
    }
    return web_path($rel);
}

/**
 * 给阿里云 OSS/CDN 地址追加图片处理参数（缩放 / 格式 / 质量）。
 * $w=0 表示不缩放（原图可仍转 webp+质量，便于详情大图与海报）。
 * 文档：https://help.aliyun.com/document_detail/44688.html
 */
function oss_process_url(string $url, int $w = 0): string
{
    if ($url === '' || !config('oss_process')) {
        return $url;
    }
    // 仅处理本站 OSS/CDN 主机，避免给第三方图乱加参数
    $base = rtrim((string)config('oss_base'), '/');
    if ($base === '' || strpos($url, $base) !== 0) {
        return $url;
    }
    if (strpos($url, 'x-oss-process=') !== false) {
        return $url;
    }

    $ops = [];
    if ($w > 0) {
        $ops[] = 'resize,m_lfit,w_' . max(1, min(4096, $w));
    }
    $fmt = strtolower(trim((string)config('oss_format')));
    if (in_array($fmt, ['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp'], true)) {
        $ops[] = 'format,' . ($fmt === 'jpeg' ? 'jpg' : $fmt);
    }
    $q = (int)config('oss_quality');
    if ($q > 0 && $q <= 100) {
        $ops[] = 'quality,q_' . $q;
    }
    if (!$ops) {
        return $url;
    }
    $process = 'image/' . implode('/', $ops);
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . 'x-oss-process=' . $process;
}

/** 统一媒体 URL：绝对化 + 可选 OSS 图片处理 */
function media_url(string $src, int $w = 0): string
{
    $abs = media_abs($src);
    if ($abs === '') {
        return '';
    }
    return oss_process_url($abs, $w);
}

function fmt_date(?string $s): string
{
    if (!$s) {
        return '';
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : '';
}
