#!/usr/bin/env bash
# 最小冒烟：语法、配置能加载、内置服务器能应答。不抓取、不要求 data/。
# Minimal smoke: lint, config load, and one HTTP round-trip. No dataset required.
#
#   ./scripts/smoke.sh
#   make smoke
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP="${PHP:-php}"

if ! command -v "$PHP" >/dev/null 2>&1; then
  echo "找不到 PHP 解释器（$PHP）。安装 PHP >= 8.1 并启用 pdo_sqlite、mbstring。" >&2
  exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
  echo "找不到 curl，无法做 HTTP 冒烟。" >&2
  exit 1
fi

echo "==> PHP"
"$PHP" -r 'if (PHP_VERSION_ID < 80100) { fwrite(STDERR, "需要 PHP >= 8.1，当前 ".PHP_VERSION."\n"); exit(1); } foreach (["pdo_sqlite","mbstring"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "缺少扩展 {$e}\n"); exit(1); } } echo "php ".PHP_VERSION." + pdo_sqlite + mbstring\n";'

echo "==> php -l"
shopt -s nullglob
files=(index.php router.php lib.php lib_md.php thumb.php config.example.php api/*.php scripts/*.php)
for f in "${files[@]}"; do
  "$PHP" -l "$f" >/dev/null
done
echo "syntax ok (${#files[@]} files)"

echo "==> config"
"$PHP" -r 'require "lib.php"; $m=(string)config("image_mode"); if (!in_array($m, ["cdn","oss","local"], true)) { fwrite(STDERR, "image_mode 非法: {$m}\n"); exit(1);} echo "image_mode={$m} site_origin=".(config("site_origin")===""?"(auto)\n":config("site_origin")."\n");'

PORT="${SMOKE_PORT:-}"
if [ -z "$PORT" ]; then
  PORT="$("$PHP" -r '$s=stream_socket_server("tcp://127.0.0.1:0", $e, $err); if(!$s){fwrite(STDERR,$err); exit(1);} $n=stream_socket_get_name($s,false); fclose($s); echo substr(strrchr($n, ":"), 1);')"
fi

LOG="${TMPDIR:-/tmp}/chiguafun-smoke.log"
"$PHP" -S "127.0.0.1:${PORT}" -t . router.php >"$LOG" 2>&1 &
PID=$!
cleanup() {
  if kill -0 "$PID" 2>/dev/null; then
    kill "$PID" 2>/dev/null || true
    wait "$PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

echo "==> HTTP 127.0.0.1:${PORT}"
ready=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if curl -sS -o /dev/null --max-time 1 "http://127.0.0.1:${PORT}/" 2>/dev/null; then
    ready=1
    break
  fi
  sleep 0.15
done
if [ "$ready" != "1" ]; then
  echo "内置服务器没有在 127.0.0.1:${PORT} 起来。日志：" >&2
  cat "$LOG" >&2 || true
  exit 1
fi

body="$(mktemp)"
code="$(curl -sS -o "$body" -w '%{http_code}' --max-time 5 "http://127.0.0.1:${PORT}/")"
if [ "$code" != "200" ]; then
  echo "首页返回 HTTP ${code}（期望 200）" >&2
  cat "$body" >&2 || true
  exit 1
fi
if ! grep -q -E '索引还没生成|ChiguaNana|提示词' "$body"; then
  echo "首页正文不像画廊或空索引说明。" >&2
  exit 1
fi
echo "home ${code}"

# 非本机 Host、且没有 index.html：应直接给出说明，而不是 301 到缺失的静态页。
priv="$(mktemp)"
hdrs="$(mktemp)"
code="$(curl -sS -D "$hdrs" -o "$priv" -w '%{http_code}' --max-time 5 \
  -H 'Host: gallery.example.com' \
  -H 'X-Forwarded-Proto: https' \
  "http://127.0.0.1:${PORT}/")"
if [ "$code" != "200" ]; then
  echo "私有域名首页返回 HTTP ${code}（期望 200，不要跳到缺失的 index.html）" >&2
  cat "$hdrs" >&2 || true
  exit 1
fi
if grep -qi '^Location:' "$hdrs"; then
  echo "私有域名首页不应重定向" >&2
  cat "$hdrs" >&2
  exit 1
fi
if ! grep -q '索引还没生成' "$priv"; then
  echo "私有域名首页没有空索引说明" >&2
  exit 1
fi
echo "private-host home 200"

# 已有静态首页时，非本机 Host 直接输出 index.html；127.0.0.1 仍走动态页。
printf '%s\n' '<!DOCTYPE html><title>static-ok</title>static-marker' > index.html
cleanup_index() { rm -f index.html; }
trap 'cleanup_index; cleanup' EXIT
stat_body="$(mktemp)"
code="$(curl -sS -o "$stat_body" -w '%{http_code}' --max-time 5 -H 'Host: gallery.example.com' "http://127.0.0.1:${PORT}/")"
if [ "$code" != "200" ] || ! grep -q 'static-marker' "$stat_body"; then
  echo "已有 index.html 时私有域名应返回静态首页（HTTP ${code}）" >&2
  exit 1
fi
loc_body="$(mktemp)"
code="$(curl -sS -o "$loc_body" -w '%{http_code}' --max-time 5 "http://127.0.0.1:${PORT}/")"
if [ "$code" != "200" ] || ! grep -q '索引还没生成' "$loc_body"; then
  echo "127.0.0.1 在已有 index.html 时仍应走动态空索引页（HTTP ${code}）" >&2
  exit 1
fi
rm -f index.html
trap cleanup EXIT
echo "static vs local ok"

clear_body="$(mktemp)"
code="$(curl -sS -o "$clear_body" -w '%{http_code}' --max-time 8 "http://127.0.0.1:${PORT}/api/tj.php?act=clear&pwd=123456")"
if [ "$code" != "403" ] || ! grep -q 'TRAFFIC_CLEAR_PWD' "$clear_body"; then
  echo "未配置清空密码时应拒绝（HTTP ${code}）" >&2
  cat "$clear_body" >&2 || true
  exit 1
fi
echo "traffic clear disabled"

api="$(mktemp)"
code="$(curl -sS -o "$api" -w '%{http_code}' --max-time 5 "http://127.0.0.1:${PORT}/api/list.php")"
if [ "$code" = "503" ]; then
  grep -q 'index_not_ready' "$api"
  echo "list 503 index_not_ready"
elif [ "$code" = "200" ]; then
  grep -q 'items' "$api"
  echo "list 200"
else
  echo "/api/list.php 返回 HTTP ${code}" >&2
  cat "$api" >&2 || true
  exit 1
fi

echo "smoke ok"
