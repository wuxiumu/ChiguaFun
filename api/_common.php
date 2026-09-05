<?php
/**
 * API 公共引导：统一的 JSON / CORS / 缓存头与输出助手。
 * 所有 api/*.php 第一行 require 本文件即可。
 *
 * CORS 由 config('cors_origin') 控制：
 *   '*'                允许任意来源（开源演示默认）
 *   'https://a.com'    仅允许该来源（自动带 Vary: Origin）
 *   ''                 不下发 CORS 头（纯同源部署）
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

/** 下发标准响应头；OPTIONS 预检直接 204 结束。 */
function api_headers(int $maxAge = 15): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('Referrer-Policy: no-referrer-when-downgrade');

    $origin = (string)config('cors_origin');
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        if ($origin !== '*') {
            header('Vary: Origin');
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }
}

/** 统一 JSON 输出（中文不转义、斜杠不转义）。 */
function json_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 统一错误输出：设置状态码 + {"error": "..."}。 */
function api_error(int $code, string $msg): void
{
    http_response_code($code);
    json_out(['error' => $msg]);
}

/** 索引未就绪时统一 503，返回 true 表示已处理、调用方应 return。 */
function api_require_db(): bool
{
    if (!db_exists()) {
        api_error(503, 'index_not_ready');
        return true;
    }
    return false;
}

/** 把一行 items 记录映射为列表卡片字段（list/search/random 共用）。 */
function api_item_card(array $r): array
{
    return [
        'id'          => (int)$r['id'],
        'slug'        => (string)$r['slug'],
        'title'       => (string)$r['title'],
        'model'       => (string)$r['model'],
        'media_type'  => (string)($r['media_type'] ?: 'image'),
        'cover'       => !empty($r['cover']) ? thumb_url((int)$r['id'], 1, 400) : '',
        'cover_w'     => (int)$r['cover_w'],
        'cover_h'     => (int)$r['cover_h'],
        'reviewed_at' => (string)$r['reviewed_at'],
        'tags'        => json_decode((string)$r['tags'], true) ?: [],
        'descr'       => (string)$r['descr'],
        'url'         => '/index.php?p=' . rawurlencode((string)$r['slug']),
    ];
}
