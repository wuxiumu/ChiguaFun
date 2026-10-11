<?php
/**
 * 极简流量统计
 *   /tj.php           查看面板（可清空）
 *   /tj.php?act=api   JSON：今日/累计 IP、PV（默认记一次访问）
 *   /tj.php?act=api&hit=0  只读，不记访问
 *   /tj.php?act=clear&pwd=***  清空统计
 */

/**
 * 读取项目根 .env（不覆盖已经存在的环境变量）。
 * PHP 内置服务器不会自动加载 .env；这里只服务流量面板，避免再引入依赖。
 */
function traffic_load_dotenv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        if ($key === '' || getenv($key) !== false) {
            continue;
        }
        $val = trim(substr($line, $eq + 1));
        $len = strlen($val);
        if ($len >= 2) {
            $q = $val[0];
            if (($q === '"' || $q === "'") && $val[$len - 1] === $q) {
                $val = substr($val, 1, -1);
            }
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
}

traffic_load_dotenv(dirname(__DIR__) . '/.env');

$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
$redisPass = getenv('REDIS_PASSWORD');
$redisPass = is_string($redisPass) && $redisPass !== '' ? $redisPass : null;
// 不提供默认清空密码。未设置 TRAFFIC_CLEAR_PWD 时清空接口保持关闭。
$clearPwd  = getenv('TRAFFIC_CLEAR_PWD');
$clearPwd  = is_string($clearPwd) ? $clearPwd : '';

$keyTodayPv = 'traffic:pv:today';
$keyTotalPv = 'traffic:pv:total';
$keyTodayIp = 'traffic:ip_hll:today';
$keyTotalIp = 'traffic:ip_hll:total';
$dateFile   = __DIR__ . '/.traffic_day';

$redis = null;
try {
    $redis = new Redis();
    $redis->connect($redisHost, $redisPort, 1.0);
    if (is_string($redisPass) && $redisPass !== '') {
        $redis->auth($redisPass);
    }
} catch (Throwable $e) {
    $redis = null;
}

$act = (string) ($_GET['act'] ?? '');

function traffic_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        if (str_contains($raw, ',')) {
            $raw = trim(explode(',', $raw, 2)[0]);
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return $raw;
        }
    }
    return '0.0.0.0';
}

function traffic_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    // CORS 由网关/nginx 统一加；此处再写会导致重复 ACAO，浏览器会拒收
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function traffic_rollover(?Redis $redis, string $dateFile, string $keyTodayPv, string $keyTodayIp): void
{
    $todayMark = date('Ymd');
    $prev = is_file($dateFile) ? trim((string) file_get_contents($dateFile)) : '';
    if ($prev === $todayMark) {
        return;
    }
    if ($redis) {
        try {
            $redis->set($keyTodayPv, 0);
            $redis->del($keyTodayIp);
        } catch (Throwable $e) {
            // ignore
        }
    }
    @file_put_contents($dateFile, $todayMark);
}

function traffic_hit(?Redis $redis, string $keyTodayPv, string $keyTotalPv, string $keyTodayIp, string $keyTotalIp): void
{
    if (!$redis) {
        return;
    }
    try {
        $ip = traffic_client_ip();
        $redis->incr($keyTodayPv);
        $redis->incr($keyTotalPv);
        $redis->pfAdd($keyTodayIp, [$ip]);
        $redis->pfAdd($keyTotalIp, [$ip]);
    } catch (Throwable $e) {
        // 统计失败不阻断
    }
}

/** @return array{today_ip:int,today_pv:int,total_ip:int,total_pv:int} */
function traffic_stats(?Redis $redis, string $keyTodayPv, string $keyTotalPv, string $keyTodayIp, string $keyTotalIp): array
{
    $out = ['today_ip' => 0, 'today_pv' => 0, 'total_ip' => 0, 'total_pv' => 0];
    if (!$redis) {
        return $out;
    }
    try {
        $out['today_pv'] = (int) $redis->get($keyTodayPv);
        $out['total_pv'] = (int) $redis->get($keyTotalPv);
        $out['today_ip'] = (int) $redis->pfCount($keyTodayIp);
        $out['total_ip'] = (int) $redis->pfCount($keyTotalIp);
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

traffic_rollover($redis, $dateFile, $keyTodayPv, $keyTodayIp);

// ---------- 清空 ----------
if ($act === 'clear') {
    $pwd = (string) ($_GET['pwd'] ?? $_POST['pwd'] ?? '');
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || (string) ($_GET['format'] ?? '') === 'json';

    if ($clearPwd === '') {
        if ($wantsJson) {
            traffic_json(['code' => -1, 'msg' => '未设置 TRAFFIC_CLEAR_PWD，清空已禁用'], 403);
        }
        http_response_code(403);
        echo '未设置 TRAFFIC_CLEAR_PWD，清空已禁用';
        exit;
    }

    if ($pwd !== $clearPwd) {
        if ($wantsJson) {
            traffic_json(['code' => -1, 'msg' => '密码错误'], 403);
        }
        http_response_code(403);
        echo '密码错误';
        exit;
    }
    if ($redis) {
        try {
            $redis->del([$keyTodayPv, $keyTotalPv, $keyTodayIp, $keyTotalIp]);
        } catch (Throwable $e) {
            if ($wantsJson) {
                traffic_json(['code' => -1, 'msg' => '清空失败'], 500);
            }
            http_response_code(500);
            echo '清空失败';
            exit;
        }
    }
    @unlink($dateFile);
    if ($wantsJson) {
        traffic_json(['code' => 0, 'msg' => '已清空全部统计']);
    }
    header('Location: tj.php');
    exit;
}

// ---------- 记访问（面板与 api 默认记一次；api&hit=0 只读）----------
$shouldHit = $act !== 'api' || (($_GET['hit'] ?? '1') !== '0');
if ($shouldHit) {
    traffic_hit($redis, $keyTodayPv, $keyTotalPv, $keyTodayIp, $keyTotalIp);
}

$stats = traffic_stats($redis, $keyTodayPv, $keyTotalPv, $keyTodayIp, $keyTotalIp);

// ---------- API ----------
if ($act === 'api') {
    traffic_json([
        'code' => 0,
        'data' => $stats,
        'redis' => $redis ? 'ok' : 'fail',
    ]);
}

// ---------- 面板 ----------
$redisOk = $redis !== null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>流量统计</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:ui-sans-serif,system-ui,sans-serif;background:#f4f5f7;color:#1a1a1a;padding:24px}
  .wrap{max-width:520px;margin:0 auto}
  h1{font-size:1.25rem;margin-bottom:16px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
  .card{background:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px #00000010}
  .card .label{font-size:13px;color:#666}
  .card .num{font-size:1.6rem;font-weight:700;margin-top:4px}
  .meta{font-size:12px;color:#888;margin-bottom:16px}
  form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  input[type=password]{flex:1;min-width:140px;padding:8px 10px;border:1px solid #ddd;border-radius:8px}
  button{padding:8px 14px;border:0;border-radius:8px;background:#222;color:#fff;cursor:pointer}
  button:hover{background:#444}
  code{font-size:12px;background:#eee;padding:2px 6px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">
  <h1>流量统计</h1>
  <p class="meta">Redis：<?= $redisOk ? '正常' : '不可用' ?> · <?= date('Y-m-d H:i:s') ?></p>
  <div class="grid">
    <div class="card"><div class="label">今日 IP</div><div class="num"><?= (int) $stats['today_ip'] ?></div></div>
    <div class="card"><div class="label">今日 PV</div><div class="num"><?= (int) $stats['today_pv'] ?></div></div>
    <div class="card"><div class="label">总 IP</div><div class="num"><?= (int) $stats['total_ip'] ?></div></div>
    <div class="card"><div class="label">总 PV</div><div class="num"><?= (int) $stats['total_pv'] ?></div></div>
  </div>
  <div class="card" style="margin-bottom:16px">
    <div class="label" style="margin-bottom:8px">清空统计</div>
    <?php if ($clearPwd === ''): ?>
    <p class="meta" style="margin:0">清空已禁用。设置环境变量 <code>TRAFFIC_CLEAR_PWD</code> 后才会出现密码框（见 <code>.env.example</code>）。没有默认密码。</p>
    <?php else: ?>
    <form method="get" onsubmit="return confirm('确认清空全部统计？')">
      <input type="hidden" name="act" value="clear">
      <input type="password" name="pwd" placeholder="清空密码" required>
      <button type="submit">清空</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="meta">接口 <code>tj.php?act=api</code> · 只读 <code>tj.php?act=api&amp;hit=0</code></p>
</div>
</body>
</html>
