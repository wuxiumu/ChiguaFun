#!/usr/bin/env bash
# 一键同步：抓取 → 建索引（→ 缩略图，仅 local 模式）
#
# 用法：
#   ./scripts/sync.sh                 增量同步（CDN 模式，不下载图片，推荐）
#   ./scripts/sync.sh --full          全量重建索引
#   ./scripts/sync.sh --with-images   local 模式：下载原图到 images/ 并生成缩略图
#
# 可用环境变量覆盖解释器： PHP=/path/to/php  PYTHON=/path/to/python3
set -euo pipefail

# 项目根（脚本在 scripts/）
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP="${PHP:-php}"
PY="${PYTHON:-python3}"

WITH_IMAGES=0
BUILD_ARGS=()
for a in "$@"; do
  case "$a" in
    --with-images) WITH_IMAGES=1 ;;
    *) BUILD_ARGS+=("$a") ;;
  esac
done

echo "==> [1/2] 抓取提示词（$([ "$WITH_IMAGES" = 1 ] && echo '下载图片' || echo 'CDN 模式，不下载图片')）"
if [ "$WITH_IMAGES" = 1 ]; then
  "$PY" -u scripts/scrape.py --all --page-size 100 --workers 24
else
  "$PY" -u scripts/scrape.py --all --cdn-only --page-size 100 --workers 24
fi

echo "==> [2/2] 构建 SQLite 索引（并生成静态首页 / sitemap）"
"$PHP" scripts/build_index.php ${BUILD_ARGS[@]+"${BUILD_ARGS[@]}"}

if [ "$WITH_IMAGES" = 1 ]; then
  echo "==> 预生成缩略图（local 模式）"
  "$PHP" scripts/make_thumbs.php
fi

echo "==> 完成"
