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
        // 双轨图片：images=本地路径，cdn_images=源站 CDN；改 image_mode 即时切换
        $has = (int)$pdo->query("SELECT COUNT(*) FROM pragma_table_info('items') WHERE name='cdn_images'")->fetchColumn();
        if ($has === 0) {
            $pdo->exec("ALTER TABLE items ADD COLUMN cdn_images TEXT NOT NULL DEFAULT '[]'");
        }
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
 * 相关推荐（PC 详情首屏）：同模型优先（按收录时间倒序），不足再补近期其它模型；排除自身。
 * 移动端详情由 app.js 改为 /api/random.php 随机无限下拉，不再受本函数条数限制。
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

function decode_json_str_list(mixed $raw): array
{
    if (is_array($raw)) {
        $arr = $raw;
    } else {
        $arr = json_decode((string)$raw, true);
    }
    if (!is_array($arr)) {
        return [];
    }
    return array_values(array_filter(
        array_map(static fn($u): string => trim((string)$u), $arr),
        static fn(string $u): bool => $u !== ''
    ));
}

function is_http_url(string $u): bool
{
    return (bool)preg_match('#^https?://#i', $u);
}

/** 按条目 id 扫描本地 images/{id}-{n}.*（无索引时的兜底） */
function discover_local_images(int $id): array
{
    if ($id <= 0) {
        return [];
    }
    $out = [];
    for ($n = 1; $n <= 30; $n++) {
        $hit = '';
        foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
            $rel = 'images/' . $id . '-' . $n . '.' . $ext;
            if (is_file(ROOT_DIR . '/' . $rel)) {
                $hit = $rel;
                break;
            }
        }
        if ($hit === '') {
            break;
        }
        $out[] = $hit;
    }
    return $out;
}

/**
 * 按当前 image_mode 从一行 items 记录取出图片源列表（不拼最终 URL）。
 *   - cdn  → cdn_images（缺省时回退 images 里的 http 地址）
 *   - oss / local → images 本地相对路径（缺省时扫盘 / 再回退）
 */
function item_image_srcs(array $row): array
{
    $local = decode_json_str_list($row['images'] ?? '[]');
    $cdn   = decode_json_str_list($row['cdn_images'] ?? '[]');

    // 兼容旧库：images 列直接存的是 CDN 绝对地址
    if (!$cdn && $local && is_http_url($local[0])) {
        $cdn   = $local;
        $local = [];
    }

    $mode = (string)config('image_mode');
    if ($mode === 'cdn') {
        return $cdn ?: $local;
    }

    if ($local && !is_http_url($local[0])) {
        return $local;
    }
    $id = (int)($row['id'] ?? 0);
    $found = discover_local_images($id);
    if ($found) {
        return $found;
    }
    return $local ?: $cdn;
}

/**
 * 取某条目当前模式下的图片源列表。
 * 按 id + image_mode 记忆化；切换配置后新请求自然换源。
 */
function item_images(int $id): array
{
    static $cache = [];
    $mode = (string)config('image_mode');
    $key  = $id . '@' . $mode;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $st = db()->prepare('SELECT id, images, cdn_images FROM items WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch() ?: ['id' => $id, 'images' => '[]', 'cdn_images' => '[]'];
        $imgs = item_image_srcs($row);
    } catch (Throwable $e) {
        // 极老库无 cdn_images 列时降级
        try {
            $st = db()->prepare('SELECT id, images FROM items WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch() ?: ['id' => $id, 'images' => '[]'];
            $imgs = item_image_srcs($row);
        } catch (Throwable $e2) {
            $imgs = [];
        }
    }
    return $cache[$key] = $imgs;
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
 * 改 config.image_mode 即可切换图片源（cdn / oss / local），无需改业务代码。
 */
function thumb_url(int $id, int $n = 1, int $w = 400): string
{
    $imgs = item_images($id);
    $src  = (string)($imgs[$n - 1] ?? ($imgs[0] ?? ''));
    if ($src === '') {
        return '';
    }

    $mode = (string)config('image_mode');

    if ($mode === 'oss') {
        return media_url($src, $w);
    }

    if ($mode === 'cdn' || is_http_url($src)) {
        return cdn_url($src);
    }

    // local：预热缩略图优先，否则 thumb.php 现算
    $rel = 'thumbs/' . $id . '-' . $n . '-w' . $w . '.jpg';
    if (is_file(ROOT_DIR . '/' . $rel)) {
        return web_path($rel);
    }
    return web_path('thumb.php?id=' . $id . '&n=' . $n . '&w=' . $w);
}

/** 列表卡片 HTML（与 cards.js 的 .gcard 同构） */
function card_html(array $r): string
{
    $id    = (int)$r['id'];
    $slug  = (string)$r['slug'];
    $title = (string)$r['title'];
    $url   = detail_url($slug);
    $thumb = thumb_url($id, 1, 400);

    $img = $thumb
        ? '<img src="' . h($thumb) . '" alt="' . h($title) . '" loading="lazy" decoding="async">'
        : '<span class="ph">无图片</span>';

    return '<a class="gcard" href="' . h($url) . '">'
         . '<span class="gcard-thumb">' . $img . '</span>'
         . '<span class="gcard-t">' . h($title) . '</span>'
         . '</a>';
}
