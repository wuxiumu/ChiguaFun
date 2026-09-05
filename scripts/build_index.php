<?php
/**
 * OpenNana 提示词库 - SQLite 索引构建
 *
 * 用法：
 *   php build_index.php            增量同步（只处理新增/改动过的 md）
 *   php build_index.php --full     全量重建
 *   php build_index.php --prune    增量 + 清理已删除文件的记录
 *
 * 索引文件：cache/index.db
 */

declare(strict_types=1);

require __DIR__ . '/../lib_md.php';

// 脚本位于 scripts/，数据与缓存在项目根（上一级）
const ROOT_DIR  = __DIR__ . '/..';
const DATA_DIR  = ROOT_DIR . '/data';
const CACHE_DIR = ROOT_DIR . '/cache';
const DB_FILE   = CACHE_DIR . '/index.db';

$full   = in_array('--full', $argv ?? [], true);
$prune  = in_array('--prune', $argv ?? [], true) || $full;
$noBody = in_array('--no-body', $argv ?? [], true);   // 只索引标题/简介/标签，体积可降到 ~1/20
$isCli  = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    @set_time_limit(0);
}

if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}

$db = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec('PRAGMA temp_store=MEMORY');
$db->exec('PRAGMA cache_size=-65536');   // 64MB

if ($full) {
    $db->exec('DROP TABLE IF EXISTS items');
    $db->exec('DROP TABLE IF EXISTS ptext');
    $db->exec('DROP TABLE IF EXISTS fts');
}

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS items (
    id          INTEGER PRIMARY KEY,
    slug        TEXT    NOT NULL UNIQUE,
    title       TEXT    NOT NULL DEFAULT '',
    descr       TEXT    NOT NULL DEFAULT '',
    model       TEXT    NOT NULL DEFAULT '',
    media_type  TEXT    NOT NULL DEFAULT 'image',
    tags        TEXT    NOT NULL DEFAULT '[]',
    src_name    TEXT    NOT NULL DEFAULT '',
    src_url     TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT '',
    cover       TEXT    NOT NULL DEFAULT '',
    cover_w     INTEGER NOT NULL DEFAULT 0,
    cover_h     INTEGER NOT NULL DEFAULT 0,
    images      TEXT    NOT NULL DEFAULT '[]',
    videos      TEXT    NOT NULL DEFAULT '[]',
    reviewed_at TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT '',
    file        TEXT    NOT NULL DEFAULT '',
    mtime       INTEGER NOT NULL DEFAULT 0
);
SQL);

// 轻量迁移：老库补列
foreach (['cover_w' => 'INTEGER NOT NULL DEFAULT 0', 'cover_h' => 'INTEGER NOT NULL DEFAULT 0', 'videos' => "TEXT NOT NULL DEFAULT '[]'"] as $col => $def) {
    $has = $db->query("SELECT COUNT(*) FROM pragma_table_info('items') WHERE name='$col'")->fetchColumn();
    if (!$has) {
        $db->exec("ALTER TABLE items ADD COLUMN $col $def");
    }
}
$db->exec('CREATE INDEX IF NOT EXISTS ix_items_model    ON items(model)');
$db->exec('CREATE INDEX IF NOT EXISTS ix_items_reviewed ON items(reviewed_at DESC)');
$db->exec('CREATE INDEX IF NOT EXISTS ix_items_type     ON items(media_type)');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ptext (
    item_id INTEGER NOT NULL,
    seq     INTEGER NOT NULL,
    label   TEXT    NOT NULL DEFAULT '',
    body    TEXT    NOT NULL DEFAULT '',
    chars   INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id, seq)
);
SQL);

// trigram 分词：对中文子串检索友好（要求查询词 >= 3 字符，更短的走 LIKE 回退）
$db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS fts USING fts5(body, item_id UNINDEXED, tokenize='trigram')");

/** 读封面图宽高（只读文件头，很快）。读不到返回 0,0 */
function cover_size(string $rel): array
{
    static $cache = [];
    if ($rel === '') {
        return [0, 0];
    }
    if (isset($cache[$rel])) {
        return $cache[$rel];
    }
    $p = ROOT_DIR . '/' . ltrim(str_replace('\\', '/', $rel), '/');
    if (!is_file($p)) {
        return $cache[$rel] = [0, 0];
    }
    $info = @getimagesize($p);
    return $cache[$rel] = ($info === false ? [0, 0] : [(int)$info[0], (int)$info[1]]);
}

// ------------------------------------------------------------------ 同步
$files = glob(DATA_DIR . '/*.md') ?: [];
sort($files);

$known = [];
foreach ($db->query('SELECT file, mtime FROM items') as $row) {
    $known[$row['file']] = (int)$row['mtime'];
}

$todo = [];
foreach ($files as $f) {
    $base = basename($f);
    $mt   = (int)@filemtime($f);
    if (!isset($known[$base]) || $known[$base] !== $mt) {
        $todo[] = [$f, $base, $mt];
    }
}

$insItem = $db->prepare(
    'INSERT INTO items (id,slug,title,descr,model,media_type,tags,src_name,src_url,url,cover,cover_w,cover_h,images,videos,reviewed_at,created_at,file,mtime)
     VALUES (:id,:slug,:title,:descr,:model,:media_type,:tags,:src_name,:src_url,:url,:cover,:cover_w,:cover_h,:images,:videos,:reviewed_at,:created_at,:file,:mtime)
     ON CONFLICT(id) DO UPDATE SET
       slug=excluded.slug, title=excluded.title, descr=excluded.descr, model=excluded.model,
       media_type=excluded.media_type, tags=excluded.tags, src_name=excluded.src_name,
       src_url=excluded.src_url, url=excluded.url, cover=excluded.cover,
       cover_w=excluded.cover_w, cover_h=excluded.cover_h, images=excluded.images, videos=excluded.videos,
       reviewed_at=excluded.reviewed_at, created_at=excluded.created_at, file=excluded.file, mtime=excluded.mtime'
);
$delText = $db->prepare('DELETE FROM ptext   WHERE item_id = ?');
$delFts  = $db->prepare('DELETE FROM fts     WHERE item_id = ?');
$insText = $db->prepare('INSERT OR REPLACE INTO ptext (item_id,seq,label,body,chars) VALUES (?,?,?,?,?)');
$insFts  = $db->prepare('INSERT INTO fts (body,item_id) VALUES (?,?)');

$t0     = microtime(true);
$done   = 0;
$errors = 0;

$db->beginTransaction();
foreach ($todo as [$path, $base, $mt]) {
    $r = parse_md_file($path);
    if (!$r || $r['slug'] === '') {
        $errors++;
        continue;
    }

    // 按 image_mode 选择图片来源：
    //   cdn   —— frontmatter 的 source_images（CDN 原图），无本地文件则不读宽高
    //   local —— 正文 ![]() 的本地相对路径，读文件头拿宽高做占位
    $isCdn = config('image_mode') === 'cdn';
    $imgs  = $isCdn
        ? (array)($r['source_images'] ?: $r['images'])
        : (array)$r['images'];
    $imgs  = array_values(array_filter(
        array_map('strval', $imgs),
        static fn($u) => $u !== ''
    ));
    $cover = $imgs[0] ?? '';
    [$cw, $ch] = $isCdn ? [0, 0] : cover_size($cover);

    $insItem->execute([
        ':id'          => $r['id'],
        ':slug'        => $r['slug'],
        ':title'       => $r['title'],
        ':descr'       => $r['desc'],
        ':model'       => $r['model'],
        ':media_type'  => $r['type'],
        ':tags'        => json_encode(array_values($r['tags']), JSON_UNESCAPED_UNICODE),
        ':src_name'    => $r['srcName'],
        ':src_url'     => $r['srcUrl'],
        ':url'         => $r['url'],
        ':cover'       => $cover,
        ':cover_w'     => $cw,
        ':cover_h'     => $ch,
        ':images'      => json_encode($imgs, JSON_UNESCAPED_UNICODE),
        ':videos'      => json_encode(array_values($r['videos']), JSON_UNESCAPED_UNICODE),
        ':reviewed_at' => $r['reviewed'],
        ':created_at'  => $r['created'],
        ':file'        => $base,
        ':mtime'       => $mt,
    ]);

    $delText->execute([$r['id']]);
    $delFts->execute([$r['id']]);

    // 检索语料：标题 + 简介 + 模型 + 标签（+ 提示词正文）
    $buf = $r['title'] . ' ' . $r['desc'] . ' ' . $r['model'] . ' ' . implode(' ', $r['tags']);
    foreach ($r['prompts'] as $i => $p) {
        $insText->execute([$r['id'], $i, $p['label'], $p['text'], mb_strlen($p['text'])]);
        if (!$noBody) {
            $buf .= ' ' . $p['text'];
        }
    }
    $insFts->execute([$buf, $r['id']]);

    $done++;
    if ($done % 500 === 0) {
        $db->commit();
        $db->beginTransaction();
        printf("  …已处理 %d / %d\r", $done, count($todo));
    }
}
$db->commit();

// 清理已删除
$removed = 0;
if ($prune) {
    $live = array_map('basename', $files);
    $st   = $db->query('SELECT id, file FROM items');
    $db->beginTransaction();
    foreach ($st as $row) {
        if (!in_array($row['file'], $live, true)) {
            $db->prepare('DELETE FROM items WHERE id = ?')->execute([$row['id']]);
            $db->prepare('DELETE FROM ptext WHERE item_id = ?')->execute([$row['id']]);
            $db->prepare('DELETE FROM fts   WHERE item_id = ?')->execute([$row['id']]);
            $removed++;
        }
    }
    $db->commit();
}

$db->exec('DELETE FROM fts WHERE item_id NOT IN (SELECT id FROM items)');

// 全量优化代价是 O(整表)，小改动时不跑，避免增量同步被拖慢
$heavy = $full || $done > 1000;
if ($heavy) {
    echo "  整理全文索引…\n";
    $db->exec("INSERT INTO fts(fts) VALUES('optimize')");
    $db->exec('ANALYZE');
}

$total = (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn();
$secs  = microtime(true) - $t0;
$dbsize = is_file(DB_FILE) ? round(filesize(DB_FILE) / 1048576, 1) : 0;

printf(
    "\n索引完成：新增/更新 %d 条，删除 %d 条，错误 %d 个\n库内总计 %d 条，耗时 %.1fs，数据库 %.1f MB\n",
    $done, $removed, $errors, $total, $secs, $dbsize
);

// 索引更新后自动重新生成静态首页与 sitemap
$gen = __DIR__ . '/gen_static.php';
if (is_file($gen)) {
    echo "\n生成静态首页…\n";
    $g0 = microtime(true);
    $cmd = PHP_BINARY . ' ' . escapeshellarg($gen);
    system($cmd, $genCode);
    if ($genCode !== 0) {
        fwrite(STDERR, "警告：gen_static.php 返回非零状态 $genCode\n");
    }
}
