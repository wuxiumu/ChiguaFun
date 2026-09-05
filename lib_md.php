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
            'data_cdn'       => '',
            'cors_origin'    => '*',
            'watermark_text' => 'AI 生成',
        ];
        $file = __DIR__ . '/config.php';
        $user = is_file($file) ? (array)@include $file : [];
        $cfg  = array_merge($defaults, $user);
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/** 站点源：配置优先，其次按请求自动探测，最后回退 localhost。始终以无尾斜杠返回。 */
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

/** data/ 里记的相对路径 -> 可直接用于 <img> 的 URL */
function img_url(string $p): string
{
    $p = str_replace('\\', '/', $p);
    if (preg_match('#^https?://#i', $p)) {
        return $p;
    }
    return ltrim(preg_replace('#^(\./|\.\./)+#', '', $p), '/');
}

function fmt_date(?string $s): string
{
    if (!$s) {
        return '';
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : '';
}
