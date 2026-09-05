<?php
/**
 * PHP 内置服务器路由
 * 用途：让静态资源（CSS / 缩略图 / 图片）带上合适的 HTTP 缓存头
 *
 * 用法：
 *   php -S 127.0.0.1:8765 -t . router.php
 *
 * 返回 false 表示交给默认静态处理器（会忽略我们设置的 header）。
 * 我们自己 serve 静态文件并附加头，这样头才会真正生效。
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 安全：禁止访问上层目录
if (strpos($path, '..') !== false) {
    http_response_code(400);
    return true;
}

// 首页：直接 serve index.html，避免 PHP CLI Server 默认的 301 跳转
if ($path === '/') {
    $index = __DIR__ . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=60, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        readfile($index);
        return true;
    }
}

$fs = __DIR__ . $path;

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

// 缩略图这类 jpg 通常几十 KB，可以走 readfile 一次性发送
$size = filesize($fs);
header('Content-Length: ' . $size);

// 简单 ETag（基于 mtime + size）—— 配合客户端 304
$etag = '"' . md5($fs . '|' . filemtime($fs) . '|' . $size) . '"';
header('ETag: ' . $etag);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    return true;
}

// 如果客户端支持压缩且文件不是预压缩类型，则压缩传输
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