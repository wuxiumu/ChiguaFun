<?php
/**
 * OpenNana 提示词库 - API: 模型分布
 *
 * GET /api/models.php
 *
 * 返回各模型的条目数（倒序）与媒体类型分布，用于筛选条 / 统计图。
 *
 *   {
 *     "total": 20803,
 *     "models": [{"model":"ChatGPT","count":11379}, ...],
 *     "media":  {"image": 19000, "video": 1803}
 *   }
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

api_headers(60);
if (api_require_db()) {
    return;
}

$models = [];
foreach (model_counts() as $m => $n) {
    $models[] = ['model' => (string)$m, 'count' => (int)$n];
}

json_out([
    'total'  => total_items(),
    'models' => $models,
    'media'  => media_counts(),
]);
