<?php
/**
 * PHP 内置服务器路由
 * 用途：
 *   - 本地开发（localhost）：默认走动态 PHP，改代码即预览，不必重生静态页
 *   - 静态资源附加缓存头
 *
 * 用法：
 *   php -S localhost:1234 -t . router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 安全：禁止访问上层目录
if (strpos($path, '..') !== false) {
    http_response_code(400);
    return true;
}

function router_is_local(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

$local = router_is_local();

// 本地开发：首页与 index.html 一律动态（index.php）
if ($local && ($path === '/' || $path === '/index.html' || $path === '/index.htm')) {
    require __DIR__ . '/index.php';
    return true;
}

// 首页（生产）：直接 serve index.html；?p= / ?debug= 仍走动态
if ($path === '/') {
    $dynamic = isset($_GET['p']) || isset($_GET['debug']);
    if (!$dynamic) {
        $index = __DIR__ . '/index.html';
        if (is_file($index)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: public, max-age=60, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            readfile($index);
            return true;
        }
    }
    return false;
}

// 本地开发：详情页始终动态，改 PHP/配置即可预览
if ($local && preg_match('#^/p/([^/]+)\.html$#', $path, $m)) {
    $_GET['p'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

$fs = __DIR__ . $path;

// 静态详情页缺失 → 回退动态详情（index.php?p=），保证 p/*.html 永不死链
if (preg_match('#^/p/([^/]+)\.html$#', $path, $m) && !is_file($fs)) {
    $_GET['p'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

// 仅处理存在的文件；不存在交给 PHP 默认处理（返回 404）
if (!is_file($fs)) {
    return false;
}

// 真实文件但路径属于 PHP 脚本？交回去
if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
    return false;
}

// HTML 文件交给默认静态处理器（短缓存，自动识别 text/html）
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($ext === 'html' || $ext === 'htm') {
    return false;
}

$mime = match ($ext) {
    'css'  => 'text/css; charset=utf-8',
    'js'   => 'application/javascript; charset=utf-8',
    'jpg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'svg'  => 'image/svg+xml',
    'ico'  => 'image/x-icon',
    'woff', 'woff2' => 'font/woff2',
    'txt', 'md' => 'text/plain; charset=utf-8',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800, immutable'); // 7 天 + 永久指纹
header('X-Content-Type-Options: nosniff');

$size = filesize($fs);
header('Content-Length: ' . $size);

$etag = '"' . md5($fs . '|' . filemtime($fs) . '|' . $size) . '"';
header('ETag: ' . $etag);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    return true;
}

$ae = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
$compressible = !preg_match('/\.(jpe?g|png|gif|webp|gz|brz|woff2?|ico)$/i', $path);
if ($compressible && strpos($ae, 'gzip') !== false && extension_loaded('zlib')
    && function_exists('ob_gzhandler') && $size > 1024) {
    $data = file_get_contents($fs);
    if ($data !== false) {
        $gz = gzencode($data, 6);
        if ($gz !== false && strlen($gz) < $size) {
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($gz));
            header('Vary: Accept-Encoding');
            echo $gz;
            return true;
        }
    }
}

readfile($fs);
return true;
