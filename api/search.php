<?php
/**
 * OpenNana 提示词库 - API: 搜索
 *
 * GET /api/search.php?q=关键词&page=1&limit=20&model=X
 *
 * 行为类似 list.php，但强制要求 q（缺失返回 400），专给搜索框用。
 * q >= 3 字符走 SQLite FTS5(trigram) 全文检索，更短的回退 LIKE。
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

api_headers(15);
if (api_require_db()) {
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    api_error(400, 'q_required');
    return;
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(60, (int)($_GET['limit'] ?? PER_PAGE)));
$offset = ($page - 1) * $limit;
$model  = trim((string)($_GET['model'] ?? ''));

$total = count_items($q, $model);
$pages = max(1, (int)ceil($total / $limit));
$page  = min($page, $pages);

$rows  = query_items($q, $model, $limit, $offset);
$items = array_map('api_item_card', $rows);

json_out([
    'total'    => $total,
    'page'     => $page,
    'per_page' => $limit,
    'pages'    => $pages,
    'q'        => $q,
    'model'    => $model,
    'items'    => $items,
]);
