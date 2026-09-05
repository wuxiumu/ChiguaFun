<?php
/**
 * OpenNana 提示词库 - API: 图片同源代理
 *
 * GET /api/imgproxy.php?u=<CDN 图片地址>
 *
 * 用途：海报 Canvas 需要绘制 CDN 原图并导出（toDataURL），但源 CDN 不下发
 * CORS 头，跨域绘入会污染画布。本接口把图片以同源回传，画布即可安全导出。
 *
 * 安全：仅允许白名单域名（防开放代理 / SSRF）；不回传任意 URL。
 * 缓存：长缓存 + ETag，避免海报重复生成时反复回源。
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

// 仅允许这些图片主机，防止本接口被当作开放代理
$ALLOWED_HOSTS = ['img.opennana.com'];

$u = trim((string)($_GET['u'] ?? ''));
if ($u === '' || !preg_match('#^https?://#i', $u)) {
    http_response_code(400);
    exit;
}

$host = (string)(parse_url($u, PHP_URL_HOST) ?? '');
if (!in_array($host, $ALLOWED_HOSTS, true)) {
    http_response_code(403);
    exit;
}

// 只放行图片扩展名
$ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
$mime = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
][$ext] ?? null;
if ($mime === null) {
    http_response_code(415);
    exit;
}

$ctx = stream_context_create(['http' => [
    'timeout'    => 20,
    'header'     => "User-Agent: Mozilla/5.0 (opennana-poster)\r\nReferer: https://opennana.com/\r\n",
    'max_redirects' => 3,
]]);
$raw = @file_get_contents($u, false, $ctx);
if ($raw === false || $raw === '') {
    http_response_code(502);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800, immutable');
header('X-Content-Type-Options: nosniff');
$etag = '"' . md5($u) . '"';
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($raw));
echo $raw;
