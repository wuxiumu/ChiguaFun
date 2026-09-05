<?php
/**
 * OpenNana 提示词库 - 数据访问层（SQLite）
 * 索引由 build_index.php 生成，本文件只负责查询。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib_md.php';

const ROOT_DIR  = __DIR__;
const DATA_DIR  = __DIR__ . '/data';
const IMAGE_DIR = __DIR__ . '/images';
const THUMB_DIR = __DIR__ . '/thumbs';
const CACHE_DIR = __DIR__ . '/cache';
const DB_FILE   = CACHE_DIR . '/index.db';
const PER_PAGE  = 36;

/** @var PDO|null */
$GLOBALS['__db'] = null;

function db_exists(): bool
{
    return is_file(DB_FILE);
}

function db(): PDO
{
    if (!db_exists()) {
        throw new RuntimeException('索引尚未生成，请先运行： php build_index.php');
    }
    if ($GLOBALS['__db'] === null) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA cache_size=-32768');   // 32MB
        $pdo->exec('PRAGMA mmap_size=268435456'); // 256MB
        $GLOBALS['__db'] = $pdo;
    }
    return $GLOBALS['__db'];
}

/** 30 秒文件级记忆化，避免重复跑聚合查询 */
function memo(string $key, callable $fn, int $ttl = 30)
{
    $f = CACHE_DIR . '/memo_' . md5($key) . '.php';
    if (!$ttl || !is_file($f) || time() - filemtime($f) > $ttl) {
        $v = $fn();
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        @file_put_contents($f, '<?php return ' . var_export($v, true) . ';', LOCK_EX);
        return $v;
    }
    return include $f;
}

// ------------------------------------------------------------------ 查询构造

function build_where(string $q, string $model, array &$p): string
{
    $w = [];
    if ($model !== '') {
        $w[]           = 'i.model = :model';
        $p[':model']   = $model;
    }
    if ($q !== '') {
        if (mb_strlen($q) >= 3) {
            // FTS5 trigram：要求 >=3 字符，短词走下面 LIKE
            $w[]     = 'i.id IN (SELECT item_id FROM fts WHERE body MATCH :q)';
            $p[':q'] = '"' . str_replace('"', '""', $q) . '"';
        } else {
            $w[]      = '(i.title LIKE :lk ESCAPE \'\\\' OR i.descr LIKE :lk ESCAPE \'\\\' '
                      . 'OR i.tags LIKE :lk ESCAPE \'\\\' OR i.model LIKE :lk ESCAPE \'\\\')';
            $p[':lk'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
    }
    return $w ? 'WHERE ' . implode(' AND ', $w) : '';
}

function query_items(string $q, string $model, int $limit, int $offset): array
{
    $db = db();
    try {
        $p = [];
        $sql = 'SELECT i.* FROM items i ' . build_where($q, $model, $p)
             . ' ORDER BY i.reviewed_at DESC, i.id DESC LIMIT :lim OFFSET :off';
        $st = $db->prepare($sql);
        foreach ($p as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        // FTS 查询可能因特殊字符失败 -> 降级为 LIKE
        if ($q !== '' && mb_strlen($q) >= 3) {
            $bind = [];
            $where = [];
            if ($model !== '') {
                $where[]      = 'i.model = :model';
                $bind[':model'] = $model;
            }
            $where[]    = '(i.title LIKE :lk ESCAPE \'\\\' OR i.descr LIKE :lk ESCAPE \'\\\')';
            $bind[':lk'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $sql = 'SELECT i.* FROM items i WHERE ' . implode(' AND ', $where)
                 . ' ORDER BY i.reviewed_at DESC, i.id DESC LIMIT :lim OFFSET :off';
            $st = $db->prepare($sql);
            foreach ($bind as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->bindValue(':off', $offset, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll();
        }
        throw $e;
    }
}

function count_items(string $q, string $model): int
{
    return (int)memo("cnt:$q|$model", static function () use ($q, $model) {
        $p   = [];
        $sql = 'SELECT COUNT(*) FROM items i ' . build_where($q, $model, $p);
        $st  = db()->prepare($sql);
        foreach ($p as $k => $v) {
            $st->bindValue($k, $v);
        }
        try {
            $st->execute();
        } catch (PDOException $e) {
            return 0;
        }
        return (int)$st->fetchColumn();
    }, 15);
}

function total_items(): int
{
    return (int)memo('total', static fn() => (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn(), 15);
}

function model_counts(): array
{
    return memo('models', static function () {
        $st = db()->query(
            'SELECT model, COUNT(*) AS n FROM items WHERE model <> \'\' GROUP BY model ORDER BY n DESC'
        );
        $out = [];
        foreach ($st as $r) {
            $out[$r['model']] = (int)$r['n'];
        }
        return $out;
    });
}

function media_counts(): array
{
    return memo('media', static function () {
        $st = db()->query('SELECT media_type, COUNT(*) AS n FROM items GROUP BY media_type');
        $out = [];
        foreach ($st as $r) {
            $out[$r['media_type'] ?: 'image'] = (int)$r['n'];
        }
        return $out;
    });
}

function image_file_count(): int
{
    // 走数据库而不是 glob，2 万文件的目录扫描太慢
    return (int)memo('imgcount', static fn() => (int)db()
        ->query("SELECT COUNT(*) FROM items WHERE cover <> ''")->fetchColumn(), 60);
}

function get_item(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM items WHERE slug = ?');
    $st->execute([$slug]);
    $r = $st->fetch();
    return $r ?: null;
}

function get_prompts(int $id): array
{
    $st = db()->prepare('SELECT seq, label, body, chars FROM ptext WHERE item_id = ? ORDER BY seq');
    $st->execute([$id]);
    return $st->fetchAll();
}

/**
 * 相关推荐：同模型优先（按收录时间倒序），不足 limit 再补近期其它模型；排除自身。
 * 返回精简字段（id/slug/title/cover/cover_w/cover_h），供详情页.related-grid 使用。
 */
function related_items(array $cur, int $limit = 16): array
{
    $db    = db();
    $out   = [];
    $model = (string)$cur['model'];
    if ($model !== '') {
        $st = $db->prepare(
            'SELECT id,slug,title,cover,cover_w,cover_h FROM items
             WHERE model = :m AND id != :id ORDER BY reviewed_at DESC, id DESC LIMIT :l'
        );
        $st->bindValue(':m', $model);
        $st->bindValue(':id', (int)$cur['id']);
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->execute();
        $out = $st->fetchAll();
    }
    if (count($out) < $limit) {
        $need = $limit - count($out);
        $ids  = array_map(static fn($r): int => (int)$r['id'], $out);
        $ids[] = (int)$cur['id'];
        $st = $db->query(
            'SELECT id,slug,title,cover,cover_w,cover_h FROM items WHERE id NOT IN ('
            . implode(',', $ids) . ') ORDER BY reviewed_at DESC, id DESC LIMIT ' . (int)$need
        );
        foreach ($st as $r) {
            $out[] = $r;
        }
    }
    return $out;
}

/** 详情上下篇：无搜索词时用索引比较（快），有搜索词时全量取 id 定位 */
function get_neighbors(array $cur, string $q, string $model): array
{
    $db = db();
    if ($q === '') {
        $mw   = $model !== '' ? ' AND model = :model' : '';
        $prev = $db->prepare(
            "SELECT id, slug, title FROM items WHERE reviewed_at > :rv$mw ORDER BY reviewed_at ASC, id ASC LIMIT 1"
        );
        $next = $db->prepare(
            "SELECT id, slug, title FROM items WHERE reviewed_at < :rv$mw ORDER BY reviewed_at DESC, id DESC LIMIT 1"
        );
        foreach ([$prev, $next] as $st) {
            $st->bindValue(':rv', $cur['reviewed_at']);
            if ($model !== '') {
                $st->bindValue(':model', $model);
            }
            $st->execute();
        }
        return [$prev->fetch() ?: null, $next->fetch() ?: null];
    }

    // 有搜索词：取出过滤后的有序 id 列表再定位
    $p   = [];
    $sql = 'SELECT i.id, i.slug, i.title FROM items i ' . build_where($q, '', $p)
         . ' ORDER BY i.reviewed_at DESC, i.id DESC';
    $st  = $db->prepare($sql);
    foreach ($p as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();
    $rows = $st->fetchAll();
    $pos  = -1;
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === (int)$cur['id']) {
            $pos = $i;
            break;
        }
    }
    if ($pos < 0) {
        return [null, null];
    }
    return [$rows[$pos - 1] ?? null, $rows[$pos + 1] ?? null];
}

// ------------------------------------------------------------------ 图片 URL

/**
 * 取某条目的图片引用数组（DB 里存的值随 image_mode 而异）：
 *   - cdn   模式：完整的 CDN 原图 URL（来自 md frontmatter 的 source_images）
 *   - local 模式：本地相对路径 images/<id>-<n>.<ext>
 * 每请求按 id 记忆化，主键查询开销可忽略。
 */
function item_images(int $id): array
{
    static $cache = [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    try {
        $st = db()->prepare('SELECT images FROM items WHERE id = ?');
        $st->execute([$id]);
        $row  = $st->fetch();
        $imgs = $row ? (json_decode((string)$row['images'], true) ?: []) : [];
    } catch (Throwable $e) {
        $imgs = [];
    }
    return $cache[$id] = $imgs;
}

/** 按配置把 CDN 原图域名改写为自定义图床/反代；未配置则原样返回。 */
function cdn_url(string $u): string
{
    $from = (string)config('cdn_replace');
    $to   = (string)config('cdn_base');
    if ($from !== '' && $to !== '' && strpos($u, $from) === 0) {
        return rtrim($to, '/') . substr($u, strlen($from));
    }
    return $u;
}

/**
 * 解析第 n 张图的展示 URL。签名与旧版一致，调用点无需改动。
 *   - cdn  模式：直接返回 CDN 原图 URL（无服务端缩略图，配合前端 loading=lazy）
 *   - local 模式：优先预热好的 thumbs/ 静态文件，否则走 thumb.php 按需生成
 * 图片引用为空时返回空串。
 */
function thumb_url(int $id, int $n = 1, int $w = 400): string
{
    $imgs = item_images($id);
    $src  = (string)($imgs[$n - 1] ?? ($imgs[0] ?? ''));
    if ($src === '') {
        return '';
    }
    // CDN / 绝对地址：直接返回（可选改写域名）
    if (preg_match('#^https?://#i', $src)) {
        return cdn_url($src);
    }
    // 本地模式：预热过的缩略图直接给静态路径，省掉一次 PHP 进程开销
    $rel = 'thumbs/' . $id . '-' . $n . '-w' . $w . '.jpg';
    if (is_file(ROOT_DIR . '/' . $rel)) {
        return $rel;
    }
    return 'thumb.php?id=' . $id . '&n=' . $n . '&w=' . $w;
}
