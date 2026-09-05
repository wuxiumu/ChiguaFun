<?php
/**
 * OpenNana 提示词库 - API: 列表
 *
 * GET /api/list.php?page=N&limit=L&model=X&q=X
 *
 * 返回 JSON：
 *   {
 *     "total": 20803, "page": 1, "per_page": 36, "pages": 578,
 *     "q": "", "model": "",
 *     "models": {"ChatGPT": 11379, ...},
 *     "items": [
 *       {"id":1,"slug":"...","title":"...","model":"...","media_type":"image",
 *        "cover":"https://img.opennana.com/...jpg","cover_w":0,"cover_h":0,
 *        "reviewed_at":"2026-09-01","tags":["..."],"descr":"...",
 *        "url":"/?p=slug"}
 *     ]
 *   }
 *
 * 说明：
 *   - cover 在 cdn 模式是 CDN 原图绝对地址，local 模式是本站缩略图相对路径
 *   - CORS / 缓存头由 _common.php 统一处理（config.cors_origin 可调）
 *   - 列表不返回提示词正文，正文走 detail.php
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

api_headers(15);
if (api_require_db()) {
    return;
}

$q      = trim((string)($_GET['q'] ?? ''));
$model  = trim((string)($_GET['model'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(60, (int)($_GET['limit'] ?? PER_PAGE)));
$offset = ($page - 1) * $limit;

$total = count_items($q, $model);
$pages = max(1, (int)ceil($total / $limit));
$page  = min($page, $pages);

$rows  = query_items($q, $model, $limit, $offset);
$items = array_map('api_item_card', $rows);

$models = [];
foreach (model_counts() as $m => $n) {
    $models[(string)$m] = (int)$n;
}

json_out([
    'total'     => $total,          // 当前筛选结果数
    'total_all' => total_items(),   // 全站总数（"全部" chip 计数用，不随筛选变化）
    'page'      => $page,
    'per_page'  => $limit,
    'pages'     => $pages,
    'q'         => $q,
    'model'     => $model,
    'models'    => $models,         // 各模型全局计数（facet）
    'items'     => $items,
]);
