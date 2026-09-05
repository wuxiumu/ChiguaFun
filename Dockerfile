# OpenNana 提示词库 —— 自托管镜像
#
# 构建：  docker build -t opennana .
# 运行：  docker run --rm -p 8765:8765 \
#           -v "$PWD/data:/app/data" -v "$PWD/cache:/app/cache" opennana
#
# 数据（data/）与索引（cache/）通过卷挂载持久化；镜像本身不含数据。
# 首次启动前，请在宿主机准备 data/（二选一）：
#   - 抓取：   python3 scripts/scrape.py --all --cdn-only --page-size 100 --workers 24
#   - 或还原： php scripts/fetch_data.php   （需先在 config.php 配置 data_cdn）

FROM php:8.3-cli-alpine

# pdo_sqlite + mbstring 必需（代码大量用 mb_* 且查询依赖 SQLite）；
# gd 仅 local 模式（服务端缩略图）需要；python3 供 scrape.py；
# curl/tar/unzip 供 fetch_data.php 下载解压数据包。
RUN set -eux; \
    apk add --no-cache bash python3 curl tar unzip oniguruma-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite mbstring gd

WORKDIR /app
COPY . /app
RUN chmod +x docker-entrypoint.sh scripts/sync.sh

EXPOSE 8765
ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8765", "-t", ".", "router.php"]
