<?php
/**
 * OpenNana 提示词库 - API: 详情
 *
 * GET /api/detail.php?slug=xxx
 *
 * 返回 JSON：
 *   {
 *     "id":1,"slug":"...","title":"...","model":"ChatGPT","media_type":"image",
 *     "tags":["..."],"descr":"...","src_name":"...","src_url":"...",
 *     "url":"https://opennana.com/...","reviewed_at":"...","created_at":"...",
 *     "cover":"https://img.opennana.com/...jpg","cover_w":0,"cover_h":0,
 *     "images":["https://img.opennana.com/...jpg",...],
 *     "videos":[...],
 *     "prompts":[{"seq":1,"label":"1. en","body":"...","chars":1234},...]
 *   }
 *
 * cdn 模式下 images 为 CDN 原图绝对地址；local 模式为本站缩略图相对路径。
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

api_headers(30);
if (api_require_db()) {
    return;
}

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    api_error(400, 'slug_required');
    return;
}

$cur = get_item($slug);
if (!$cur) {
    api_error(404, 'not_found');
    return;
}

$images = json_decode((string)$cur['images'], true) ?: [];
$videos = json_decode((string)($cur['videos'] ?? '[]'), true) ?: [];
$tags   = json_decode((string)$cur['tags'], true) ?: [];

$imgs = [];
foreach ($images as $i => $_) {
    $imgs[] = thumb_url((int)$cur['id'], $i + 1, 1200);
}
$vids = array_map('strval', $videos);

$prompts = [];
foreach (get_prompts((int)$cur['id']) as $p) {
    $prompts[] = [
        'seq'   => (int)$p['seq'],
        'label' => (string)$p['label'],
        'body'  => (string)$p['body'],
        'chars' => (int)$p['chars'],
    ];
}

json_out([
    'id'          => (int)$cur['id'],
    'slug'        => (string)$cur['slug'],
    'title'       => (string)$cur['title'],
    'model'       => (string)$cur['model'],
    'media_type'  => (string)($cur['media_type'] ?: 'image'),
    'tags'        => $tags,
    'descr'       => (string)$cur['descr'],
    'src_name'    => (string)$cur['src_name'],
    'src_url'     => (string)$cur['src_url'],
    'url'         => (string)$cur['url'],
    'reviewed_at' => (string)$cur['reviewed_at'],
    'created_at'  => (string)$cur['created_at'],
    'cover'       => $imgs[0] ?? '',
    'cover_w'     => (int)$cur['cover_w'],
    'cover_h'     => (int)$cur['cover_h'],
    'images'      => $imgs,
    'videos'      => $vids,
    'prompts'     => $prompts,
]);
