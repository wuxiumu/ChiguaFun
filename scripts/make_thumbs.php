<?php
/**
 * 批量预生成缩略图（避免首次浏览时逐张现算）
 *
 *   php scripts/make_thumbs.php           生成全部（w=400）
 *   php scripts/make_thumbs.php --w=600   指定宽度
 *   php scripts/make_thumbs.php --limit=500
 *
 * 仅 local 模式需要（依赖本地 images/ 原图）。cdn 模式直接用 CDN 原图，无需本脚本。
 * 可重复执行，已存在的会跳过。
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

if (config('image_mode') === 'cdn') {
    echo "当前为 cdn 模式，图片直连 CDN，无需生成缩略图。跳过。\n";
    echo "（如需本地缩略图，把 config.php 的 image_mode 改为 'local' 并先用 scrape.py 下载图片）\n";
    exit(0);
}

$w     = 400;
$limit = 0;
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--w=(\d+)$/', $a, $m)) {
        $w = max(64, min(1600, (int)$m[1]));
    } elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int)$m[1];
    }
}

if (!is_dir(THUMB_DIR)) {
    @mkdir(THUMB_DIR, 0755, true);
}

$db = db();
$sql = "SELECT id, images FROM items WHERE cover <> '' ORDER BY id";
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}
$rows = $db->query($sql)->fetchAll();

$t0    = microtime(true);
$made  = 0;
$skip  = 0;
$fail  = 0;
$bytes = 0;
$total = count($rows);

echo "开始预生成缩略图：{$total} 条，宽度 {$w}px\n";

foreach ($rows as $i => $r) {
    $id  = (int)$r['id'];
    $dst = THUMB_DIR . '/' . $id . '-1-w' . $w . '.jpg';

    if (is_file($dst) && filesize($dst) > 0) {
        $skip++;
        continue;
    }

    $images = json_decode((string)$r['images'], true) ?: [];
    $rel    = $images[0] ?? '';
    if ($rel === '') {
        continue;
    }
    $src = ROOT_DIR . '/' . ltrim(str_replace('\\', '/', (string)$rel), '/');
    if (!is_file($src)) {
        $fail++;
        continue;
    }

    $info = @getimagesize($src);
    if ($info === false) {
        $fail++;
        continue;
    }
    [$sw, $sh, $type] = $info;

    switch ($type) {
        case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $im = @imagecreatefrompng($src);  break;
        case IMAGETYPE_WEBP: $im = @imagecreatefromwebp($src); break;
        case IMAGETYPE_GIF:  $im = @imagecreatefromgif($src);  break;
        default: $im = false;
    }
    if (!$im) {
        $fail++;
        continue;
    }

    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        $flat = imagecreatetruecolor($sw, $sh);
        imagefilledrectangle($flat, 0, 0, $sw, $sh, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $im, 0, 0, 0, 0, $sw, $sh);
        imagedestroy($im);
        $im = $flat;
    }

    if ($sw > $w) {
        $th  = (int)round($sh * ($w / $sw));
        $dst2 = imagecreatetruecolor($w, $th);
        imagecopyresampled($dst2, $im, 0, 0, 0, 0, $w, $th, $sw, $sh);
        imagedestroy($im);
        $im = $dst2;
    }

    $tmp = $dst . '.tmp' . getmypid();
    if (@imagejpeg($im, $tmp, 82)) {
        @rename($tmp, $dst);
        $bytes += @filesize($dst) ?: 0;
        $made++;
    } else {
        @unlink($tmp);
        $fail++;
    }
    imagedestroy($im);
    unset($im);

    if (($i + 1) % 200 === 0) {
        $pct = ($i + 1) / $total * 100;
        $eta = ($i + 1) > 0 ? (microtime(true) - $t0) / ($i + 1) * ($total - $i - 1) : 0;
        printf("  %d/%d (%.1f%%) 已生成 %d 跳过 %d 失败 %d  预计剩余 %.0fs\n",
            $i + 1, $total, $pct, $made, $skip, $fail, $eta);
    }
}

printf(
    "完成：新增 %d 张，跳过 %d 张，失败 %d 张\n耗时 %.1fs，新增占用 %.1f MB\n",
    $made, $skip, $fail, microtime(true) - $t0, $bytes / 1048576
);
