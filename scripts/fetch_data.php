<?php
/**
 * fetch_data.php — 从 config('data_cdn') 拉取数据包并解压到 data/
 *
 * 用途：数据集托管在你自己的 CDN（不进 git），别人 clone 后一条命令即可还原 data/。
 *
 * 用法：
 *   php scripts/fetch_data.php            data/ 为空时下载解压
 *   php scripts/fetch_data.php --force    data/ 非空也覆盖下载
 *
 * 支持格式：.tar.gz / .tgz / .tar / .zip
 * 未配置 data_cdn 时，给出改用 scrape.py 抓取的提示。
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib_md.php';

$ROOT  = dirname(__DIR__);
$DATA  = $ROOT . '/data';
$force = in_array('--force', $argv ?? [], true);

$url = trim((string)config('data_cdn'));
if ($url === '') {
    fwrite(STDERR, "未配置 config('data_cdn')。\n");
    fwrite(STDERR, "  方案 A：在 config.php 设置数据包直链（tar.gz/zip），再跑本脚本。\n");
    fwrite(STDERR, "  方案 B：直接用爬虫抓取：python3 scripts/scrape.py --all --cdn-only\n");
    exit(1);
}

$existing = is_dir($DATA) ? count(glob($DATA . '/*.md') ?: []) : 0;
if ($existing > 0 && !$force) {
    fwrite(STDERR, "data/ 已有 {$existing} 个 md。加 --force 覆盖，或直接跑 build_index.php。\n");
    exit(1);
}
if (!is_dir($DATA)) {
    mkdir($DATA, 0755, true);
}

$tmp = tempnam(sys_get_temp_dir(), 'opennana_data_');
echo "下载数据包：{$url}\n";

$ok = false;
$ctx = stream_context_create(['http' => ['timeout' => 600, 'header' => "User-Agent: opennana-fetch\r\n"]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw !== false && $raw !== '') {
    file_put_contents($tmp, $raw);
    $ok = true;
} else {
    // 回退 curl
    $cmd = 'curl -fsSL ' . escapeshellarg($url) . ' -o ' . escapeshellarg($tmp);
    exec($cmd, $o, $rc);
    $ok = ($rc === 0 && filesize($tmp) > 0);
}
if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "下载失败（file_get_contents 与 curl 均不可用）。\n");
    exit(1);
}

printf("下载完成 %.1f MB，解压到 data/…\n", (float)filesize($tmp) / 1048576);

$cmd = preg_match('/\.zip(\?|#|$)/i', $url)
    ? 'unzip -oq ' . escapeshellarg($tmp) . ' -d ' . escapeshellarg($DATA)
    : 'tar -xf '  . escapeshellarg($tmp) . ' -C ' . escapeshellarg($DATA);
exec($cmd, $o, $rc);
@unlink($tmp);
if ($rc !== 0) {
    fwrite(STDERR, "解压失败：{$cmd}\n");
    exit(1);
}

$md = count(glob($DATA . '/*.md') ?: []);
// 若压缩包外层裹了一层目录，把 md 上提到 data/
if ($md === 0) {
    foreach (glob($DATA . '/*/*.md') ?: [] as $f) {
        rename($f, $DATA . '/' . basename($f));
    }
    $md = count(glob($DATA . '/*.md') ?: []);
}

printf("完成：data/ 共 %d 个 md。下一步：php scripts/build_index.php\n", $md);
