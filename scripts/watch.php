#!/usr/bin/env php
<?php
/**
 * watch.php — 增量守护脚本
 * 监控 data/ 和 images/ 目录，当新文件出现时：
 *   1. 增量重建 SQLite 索引（已是 upsert 模式，可重复跑）
 *   2. 预热缺省的 w=400 缩略图（仅新增的）
 *
 * 用法：php watch.php         # 默认每 60s 检查一次
 *       php watch.php --once  # 检查一次就退出
 *       php watch.php --interval=30
 *
 * 设计原则：
 *   - 索引构建是幂等的（ON CONFLICT DO UPDATE），重复跑无副作用
 *   - 缩略图脚本检查文件存在才处理，自然幂等
 *   - 跑批期间不会阻塞 PHP 内置服务器的请求
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib_md.php';   // 为了 config()

const SCRIPTS   = __DIR__;                 // scripts/
const ROOT      = __DIR__ . '/..';         // 项目根
const DATA_DIR  = ROOT . '/data';
const IMG_DIR   = ROOT . '/images';
const THUMB_DIR = ROOT . '/thumbs';
const DB_FILE   = ROOT . '/cache/index.db';

// ------- CLI 参数 -------
$opts = getopt('', ['once', 'interval::', 'quiet']);
$once    = isset($opts['once']);
$quiet   = isset($opts['quiet']);
$interval = (int)($opts['interval'] ?? 60);
$interval = max(10, min(600, $interval));

function log_line(string $msg, bool $quiet): void {
    if (!$quiet) {
        echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

/** 统计现有 md 数（最快的方式：直接 glob） */
function count_md(): int {
    $files = glob(DATA_DIR . '/*.md');
    return $files === false ? 0 : count($files);
}

/** 跑一次完整维护流程 */
function tick(bool $quiet): array {
    if (!is_dir(DATA_DIR)) return ['md' => 0, 'thumbs_new' => 0, 't_build' => 0, 't_thumb' => 0];

    // 1) 增量重建索引
    $t0 = microtime(true);
    ob_start();
    $cmd = PHP_BINARY . ' ' . escapeshellarg(SCRIPTS . '/build_index.php') . ' 2>&1';
    $buildOut = shell_exec($cmd);
    $buildErr = ob_get_clean();
    $tBuild = round(microtime(true) - $t0, 2);
    if (!$quiet && $buildOut !== null) echo trim($buildOut) . PHP_EOL;
    if (!$quiet && $buildErr !== null && trim($buildErr) !== '') echo '[build] ' . trim($buildErr) . PHP_EOL;

    // 2) 预热缩略图（仅 local 模式；cdn/oss 由远端图片处理，无需本地 thumbs）
    $tThumb = 0.0;
    $thumbsNew = 0;
    $mode = (string)config('image_mode');
    if ($mode !== 'cdn' && $mode !== 'oss') {
        if (!is_dir(THUMB_DIR)) mkdir(THUMB_DIR, 0755, true);
        $t0 = microtime(true);
        $beforeThumbs = count(glob(THUMB_DIR . '/*') ?: []);
        ob_start();
        $cmd = PHP_BINARY . ' ' . escapeshellarg(SCRIPTS . '/make_thumbs.php') . ' 2>&1';
        $thumbOut = shell_exec($cmd);
        $thumbErr = ob_get_clean();
        $tThumb = round(microtime(true) - $t0, 2);
        if (!$quiet && $thumbOut !== null) echo trim($thumbOut) . PHP_EOL;
        $afterThumbs = count(glob(THUMB_DIR . '/*') ?: []);
        $thumbsNew = $afterThumbs - $beforeThumbs;
    }

    return [
        'md'         => count_md(),
        'thumbs_new' => $thumbsNew,
        't_build'    => $tBuild,
        't_thumb'    => $tThumb,
    ];
}

// ------- 主循环 -------
$startMd = count_md();
log_line('守护启动，当前 md=' . $startMd . '，间隔=' . $interval . 's' . ($once ? '（单次）' : ''), $quiet);

if ($once) {
    $r = tick($quiet);
    log_line(sprintf('完成: md=%d 新增缩略图=%d  build=%.2fs thumb=%.2fs', $r['md'], $r['thumbs_new'], $r['t_build'], $r['t_thumb']), $quiet);
    exit(0);
}

$lastMd = $startMd;
while (true) {
    sleep($interval);
    $curMd = count_md();
    if ($curMd === $lastMd) {
        log_line("无变化（md=$curMd）", $quiet);
        continue;
    }
    $r = tick($quiet);
    log_line(sprintf(
        '增量: md %d→%d（+%d），新增缩略图=%d  build=%.2fs thumb=%.2fs',
        $lastMd, $r['md'], $r['md'] - $lastMd, $r['thumbs_new'], $r['t_build'], $r['t_thumb']
    ), $quiet);
    $lastMd = $r['md'];
}