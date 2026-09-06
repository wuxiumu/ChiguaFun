<?php
/**
 * OpenNana 提示词库 - API: 图片同源代理
 *
 * GET /api/imgproxy.php?u=<图片地址>
 *
 * 用途：海报 Canvas 需要绘制 CDN/OSS 原图并导出（toDataURL），但源站可能
 * 不下发 CORS 头，跨域绘入会污染画布。本接口把图片以同源回传。
 *
 * 安全：仅允许白名单域名（防开放代理 / SSRF）；不回传任意 URL。
 * 缓存：长缓存 + ETag，避免海报重复生成时反复回源。
 */

declare(strict_types=1);
require_once __DIR__ . '/_common.php';

/** 允许的图片主机：内置 + config.oss_base / cdn_base / cdn_replace 的 host */
function imgproxy_allowed_hosts(): array
{
    $hosts = ['img.opennana.com', 'chiguashentan-test.oss-cn-beijing.aliyuncs.com'];
    foreach (['oss_base', 'cdn_base', 'cdn_replace'] as $k) {
        $u = trim((string)config($k));
        if ($u === '') {
            continue;
        }
        $h = (string)(parse_url($u, PHP_URL_HOST) ?? '');
        if ($h !== '') {
            $hosts[] = $h;
        }
    }
    return array_values(array_unique($hosts));
}

$u = trim((string)($_GET['u'] ?? ''));
if ($u === '' || !preg_match('#^https?://#i', $u)) {
    http_response_code(400);
    exit;
}

$host = (string)(parse_url($u, PHP_URL_HOST) ?? '');
if (!in_array($host, imgproxy_allowed_hosts(), true)) {
    http_response_code(403);
    exit;
}

$path = (string)(parse_url($u, PHP_URL_PATH) ?: '');
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
// 允许带 x-oss-process 的地址（扩展名仍看 path）
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];
if (!isset($mimeMap[$ext])) {
    http_response_code(415);
    exit;
}

$ctx = stream_context_create(['http' => [
    'timeout'         => 20,
    'header'          => "User-Agent: Mozilla/5.0 (opennana-poster)\r\nReferer: https://banana.chiguashentan.com/\r\n",
    'max_redirects'   => 3,
    'ignore_errors'   => true,
]]);
$raw = @file_get_contents($u, false, $ctx);
if ($raw === false || $raw === '') {
    http_response_code(502);
    exit;
}

// 优先用上游 Content-Type（OSS format,webp 时 path 仍是 .jpeg）
$mime = $mimeMap[$ext];
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $hdr) {
        if (preg_match('#^Content-Type:\s*([^\s;]+)#i', $hdr, $m)) {
            $ct = strtolower($m[1]);
            if (strpos($ct, 'image/') === 0) {
                $mime = $ct;
            }
            break;
        }
    }
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
