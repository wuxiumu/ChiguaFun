<?php
/**
 * OpenNana 提示词库 - API: 总览统计
 *
 * GET /api/stats.php
 *
 * 返回全站概览：总数、含图数、媒体分布、模型分布、Top 标签云。
 * 标签云对 2 万行做一次聚合，记忆化 5 分钟，不会每次重算。
 *
 *   {
 *     "total": 20803, "with_images": 20500,
 *     "media": {"image":19000,"video":1803},
 *     "models": {"ChatGPT":11379, ...},
 *     "top_tags": {"portrait": 1200, "cinematic": 900, ...}
 *   }
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

api_headers(60);
if (api_require_db()) {
    return;
}

$topTags = memo('tagcloud', static function (): array {
    $counts = [];
    $st = db()->query("SELECT tags FROM items WHERE tags <> '[]'");
    foreach ($st as $r) {
        foreach ((json_decode((string)$r['tags'], true) ?: []) as $t) {
            $t = trim((string)$t);
            if ($t === '') {
                continue;
            }
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
    }
    arsort($counts);
    return array_slice($counts, 0, 50, true);
}, 300);

json_out([
    'total'       => total_items(),
    'with_images' => image_file_count(),
    'media'       => media_counts(),
    'models'      => model_counts(),
    'top_tags'    => $topTags,
]);
