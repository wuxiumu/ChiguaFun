<?php
/**
 * OpenNana 提示词库 - API: 随机（"手气不错" / 相关推荐下拉）
 *
 * GET /api/random.php?n=12&model=X&exclude=1,2,3
 *
 * 返回 n 条随机提示词（1..60），可选按 model 限定、exclude 排除已展示 id。
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

$exclude = [];
foreach (preg_split('/[\s,]+/', (string)($_GET['exclude'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $x) {
    $id = (int)$x;
    if ($id > 0) {
        $exclude[$id] = $id;
    }
}
$exclude = array_values($exclude);

$where = [];
$params = [];
if ($model !== '') {
    $where[] = 'model = :m';
    $params[':m'] = $model;
}
if ($exclude) {
    // 绑定位：避免拼接注入
    $ph = [];
    foreach ($exclude as $i => $id) {
        $k = ':e' . $i;
        $ph[] = $k;
        $params[$k] = $id;
    }
    $where[] = 'id NOT IN (' . implode(',', $ph) . ')';
}
$sql = 'SELECT * FROM items'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY RANDOM() LIMIT :n';

$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':n', $n, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

json_out([
    'count'   => count($rows),
    'model'   => $model,
    'exclude' => $exclude,
    'items'   => array_map('api_item_card', $rows),
]);
