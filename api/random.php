<?php
/**
 * OpenNana 提示词库 - API: 随机（"手气不错"）
 *
 * GET /api/random.php?n=12&model=X
 *
 * 返回 n 条随机提示词（1..60），可选按 model 限定。
 * 结果不缓存（max-age=0），每次请求都重新随机。
 *
 *   { "count": 12, "items": [ {同 list 卡片结构}, ... ] }
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

api_headers(0);
if (api_require_db()) {
    return;
}

$n     = max(1, min(60, (int)($_GET['n'] ?? 12)));
$model = trim((string)($_GET['model'] ?? ''));

// ORDER BY RANDOM() 在 2 万行上是毫秒级；主键表全扫一遍即可
if ($model !== '') {
    $st = db()->prepare('SELECT * FROM items WHERE model = :m ORDER BY RANDOM() LIMIT :n');
    $st->bindValue(':m', $model);
} else {
    $st = db()->prepare('SELECT * FROM items ORDER BY RANDOM() LIMIT :n');
}
$st->bindValue(':n', $n, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

json_out([
    'count' => count($rows),
    'model' => $model,
    'items' => array_map('api_item_card', $rows),
]);
