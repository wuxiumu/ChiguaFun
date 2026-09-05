# OpenNana 提示词库 —— 常用命令
# 变量可覆盖： make serve PORT=9000   /   make scrape PY=python3.12

PHP  ?= php
PY   ?= python3
HOST ?= 127.0.0.1
PORT ?= 8765

.DEFAULT_GOAL := help
.PHONY: help setup scrape scrape-sample data index thumbs static serve sync watch \
        docker-build docker-run clean clean-all

help: ## 显示本帮助
	@echo "OpenNana 提示词库 —— 可用命令："
	@echo
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'
	@echo
	@echo "典型流程： make setup && make scrape-sample && make index && make serve"

setup: ## 从模板生成 config.php（已存在则跳过）
	@if [ -f config.php ]; then echo "config.php 已存在，跳过"; else cp config.example.php config.php && echo "已生成 config.php（默认 image_mode=cdn）"; fi

scrape: ## 抓取全站（CDN 模式，不下载图片）
	$(PY) -u scripts/scrape.py --all --cdn-only --page-size 100 --workers 24

scrape-sample: ## 抓取 20 条样本（快速试用）
	$(PY) -u scripts/scrape.py --limit 20 --cdn-only --workers 8

data: ## 从 config(data_cdn) 下载数据包并解压到 data/
	$(PHP) scripts/fetch_data.php

index: ## 构建/增量更新 SQLite 索引（并自动生成静态首页 + sitemap）
	$(PHP) scripts/build_index.php

thumbs: ## 预生成缩略图（仅 local 模式需要）
	$(PHP) scripts/make_thumbs.php

static: ## 仅重新生成静态首页 index.html + sitemap
	$(PHP) scripts/gen_static.php

pages: ## 生成全部静态详情页 p/<slug>.html（SEO/中英 hreflang/提示词进描述）
	$(PHP) scripts/gen_pages.php

serve: ## 启动内置服务器（默认 http://127.0.0.1:8765，可用 HOST/PORT 覆盖）
	PHP_CLI_SERVER_WORKERS=8 $(PHP) -S $(HOST):$(PORT) -t . router.php

sync: ## 一键同步：抓取 → 建索引（CDN 模式）
	./scripts/sync.sh

watch: ## 增量守护：监听 data/ 自动重建索引
	$(PHP) scripts/watch.php --interval=90

docker-build: ## 构建 Docker 镜像 opennana
	docker build -t opennana .

docker-run: ## 运行容器（挂载 ./data 与 ./cache，映射端口，默认 8765）
	docker run --rm -p $(PORT):8765 -v "$$PWD/data:/app/data" -v "$$PWD/cache:/app/cache" opennana

clean: ## 清理可重建产物（索引 + 缩略图缓存）
	rm -rf cache thumbs
	@echo "已清理 cache/ 与 thumbs/"

clean-all: clean ## 额外清理抓取数据与静态产物
	rm -rf data images .state.json index.html sitemap.xml sitemap-items.xml robots.txt
	@echo "已清理全部生成物（data/images/静态页）"
