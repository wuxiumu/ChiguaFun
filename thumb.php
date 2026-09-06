<?php
/**
 * 按需生成缩略图并缓存到 thumbs/
 *   thumb.php?id=<itemId>&n=<第几张>&w=<宽度>
 *
 * 首次访问生成，之后直接走磁盘缓存 + 304，避免画廊页加载原图（200KB+ / 张）。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

const MAX_W = 1600;

$id = (int)($_GET['id'] ?? 0);
$n  = max(1, (int)($_GET['n'] ?? 1));
$w  = (int)($_GET['w'] ?? 400);
$w  = max(64, min(MAX_W, $w));

if ($id <= 0) {
    http_response_code(400);
    exit;
}

$cacheFile = THUMB_DIR . '/' . $id . '-' . $n . '-w' . $w . '.jpg';

// 命中缓存：走条件请求
if (is_file($cacheFile)) {
    $etag  = '"' . md5($id . '-' . $n . '-' . $w . '-' . filesize($cacheFile)) . '"';
    $mtime = filemtime($cacheFile);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    if (
        ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag ||
        strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') >= $mtime
    ) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

// 查源图
try {
    $st = db()->prepare('SELECT images FROM items WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
} catch (Throwable $e) {
    $row = null;
}
if (!$row) {
    http_response_code(404);
    exit;
}

$images = item_image_srcs($row);
// thumb.php 只处理本地文件；CDN/OSS 绝对地址不走这里
$rel = '';
foreach (array_merge(
    [ (string)($images[$n - 1] ?? '') ],
    $images
) as $cand) {
    if ($cand !== '' && !is_http_url($cand)) {
        $rel = $cand;
        break;
    }
}
if ($rel === '') {
    // 最后扫盘
    $found = discover_local_images($id);
    $rel = (string)($found[$n - 1] ?? ($found[0] ?? ''));
}
if ($rel === '') {
    http_response_code(404);
    exit;
}

$src = ROOT_DIR . '/' . ltrim(str_replace('\\', '/', $rel), '/');
if (!is_file($src)) {
    http_response_code(404);
    exit;
}

// 生成
$info = @getimagesize($src);
if ($info === false) {
    http_response_code(415);
    exit;
}
[$sw, $sh, $type] = $info;

switch ($type) {
    case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
    case IMAGETYPE_PNG:  $im = @imagecreatefrompng($src);  break;
    case IMAGETYPE_WEBP: $im = @imagecreatefromwebp($src); break;
    case IMAGETYPE_GIF:  $im = @imagecreatefromgif($src);  break;
    default:
        http_response_code(415);
        exit;
}
if (!$im) {
    http_response_code(500);
    exit;
}

// 透明通道（PNG/WebP/GIF）先铺白底，否则转 JPEG 会发黑
if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
    $flat = imagecreatetruecolor($sw, $sh);
    imagefilledrectangle($flat, 0, 0, $sw, $sh, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, $sw, $sh);
    imagedestroy($im);
    $im = $flat;
}

if ($sw > $w) {
    $tw = $w;
    $th = (int)round($sh * ($w / $sw));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    imagedestroy($im);
    $im = $dst;
}

if (!is_dir(THUMB_DIR)) {
    @mkdir(THUMB_DIR, 0755, true);
}

ob_start();
imagejpeg($im, null, 82);
$data = (string)ob_get_clean();
imagedestroy($im);

// 原子写入，避免并发下读到半截文件
$tmp = $cacheFile . '.tmp' . getmypid();
file_put_contents($tmp, $data, LOCK_EX);
@rename($tmp, $cacheFile);

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . md5($data) . '"');
header('Content-Length: ' . strlen($data));
echo $data;
