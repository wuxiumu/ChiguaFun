#!/usr/bin/env bash
# 容器入口：准备配置 → 有数据则建索引 → 启动服务（exec CMD）
set -euo pipefail
cd /app

# 1) 无本地配置：用模板生成，容器内 site_origin 留空（按请求 Host 自动探测），默认 cdn 模式
if [ ! -f config.php ]; then
  cp config.example.php config.php
  # 容器内默认留空，按请求 Host 自动探测（宿主可挂载自己的 config.php 覆盖）
  sed -i "s#'site_origin' => 'https://banana.chiguashentan.com'#'site_origin' => ''#" config.php
  sed -i "s#'cors_origin' => 'https://banana.chiguashentan.com'#'cors_origin' => '*'#" config.php
  echo "[entrypoint] 已从模板生成 config.php（image_mode=cdn，site_origin 自动探测）"
fi

# 2) 无索引但已挂载 data/：构建索引（内部会自动生成静态首页 index.html + sitemap）
if [ ! -f cache/index.db ] && compgen -G "data/*.md" > /dev/null; then
  echo "[entrypoint] 检测到 data/，构建 SQLite 索引…"
  php scripts/build_index.php
fi

# 3) 数据为空则给出提示（仍启动服务，页面会显示“索引未生成”）
if ! compgen -G "data/*.md" > /dev/null; then
  echo "[entrypoint] 警告：data/ 为空。请挂载数据卷，或在宿主机执行："
  echo "             python3 scripts/scrape.py --all --cdn-only   # 抓取"
  echo "             php scripts/fetch_data.php                   # 或从 data_cdn 还原"
fi

echo "[entrypoint] 启动： $*"
exec "$@"
