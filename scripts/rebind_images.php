<?php
/**
 * 仅回填 items.images（本地）+ items.cdn_images（源站 CDN），不重建全文索引。
 *
 * 用法：
 *   php scripts/rebind_images.php
 *
 * 场景：改完双轨图片逻辑后，快速给已有库补上 CDN 地址，
 * 之后只需改 config.image_mode 即可在 cdn / oss / local 间切换。
 */

declare(strict_types=1);
require __DIR__ . '/../lib.php';

const ROOT = __DIR__ . '/..';
const DATA = ROOT . '/data';

if (!db_exists()) {
    fwrite(STDERR, "索引不存在，请先 php scripts/build_index.php\n");
    exit(1);
}

$db = db(); // 顺带跑 cdn_images 列迁移
$st = $db->prepare(
    'UPDATE items SET images = :images, cdn_images = :cdn, cover = :cover,
     cover_w = :cw, cover_h = :ch WHERE id = :id'
);

$files = glob(DATA . '/*.md') ?: [];
$t0 = microtime(true);
$n = 0;
$miss = 0;

$db->beginTransaction();
foreach ($files as $path) {
    $r = parse_md_file($path);
    if (!$r || empty($r['id'])) {
        $miss++;
        continue;
    }
    $local = array_values(array_filter(array_map('strval', (array)($r['images'] ?? []))));
    $cdn   = array_values(array_filter(array_map('strval', (array)($r['source_images'] ?? []))));
    $cover = $local[0] ?? ($cdn[0] ?? '');
    $cw = $ch = 0;
    if ($local && !preg_match('#^https?://#i', $local[0])) {
        $p = ROOT . '/' . ltrim($local[0], '/');
        if (is_file($p)) {
            $info = @getimagesize($p);
            if ($info) {
                $cw = (int)$info[0];
                $ch = (int)$info[1];
            }
        }
    }
    $st->execute([
        ':images' => json_encode($local, JSON_UNESCAPED_UNICODE),
        ':cdn'    => json_encode($cdn, JSON_UNESCAPED_UNICODE),
        ':cover'  => $cover,
        ':cw'     => $cw,
        ':ch'     => $ch,
        ':id'     => (int)$r['id'],
    ]);
    $n++;
    if ($n % 2000 === 0) {
        $db->commit();
        $db->beginTransaction();
        printf("  …已回填 %d\r", $n);
    }
}
$db->commit();

printf(
    "[rebind_images] 更新 %d 条（跳过 %d），耗时 %.1fs · 当前 image_mode=%s\n",
    $n,
    $miss,
    microtime(true) - $t0,
    (string)config('image_mode')
);
echo "样例 thumb_url(1000) = " . thumb_url(1000, 1, 400) . "\n";
