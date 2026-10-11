<div align="center">

# 🍌 OpenNana Prompt Gallery

**A self-hosted, blazing-fast gallery for 20,000+ AI image/video prompts — zero dependencies, CDN images, millisecond full-text search.**

一个自托管的 AI 提示词画廊：收录 2 万+ 条 ChatGPT / Nano Banana / Seedance 等模型的图像与视频提示词，零第三方依赖、图片走 CDN、毫秒级全文检索。

[**English**](#-english) · [**中文**](#-中文)

![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.1-777bb3?logo=php&logoColor=white)
![Python](https://img.shields.io/badge/Python-3-3776ab?logo=python&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-FTS5-003b57?logo=sqlite&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ed?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![No deps](https://img.shields.io/badge/dependencies-none-brightgreen)

<!-- 👇 首页截图：把下面一行取消注释，并把 src 换成你托管在 CDN 上的截图（建议 1200px 宽的 GIF/截图，对 star 转化帮助极大）
<img src="https://your-cdn.example.com/opennana/screenshot.png" alt="OpenNana gallery screenshot" width="720" />
-->

<!-- 👇 Live Demo：部署后把占位地址换成你的真实站点 -->
**🔗 Live Demo:** _（部署后填写你的站点地址）_

</div>

---

## ✨ English

### Why

**Custom deploy / white-label:** WeChat **qingbao199101** · [wuxiumu@163.com](mailto:wuxiumu@163.com)

Most "prompt galleries" are either closed SaaS or heavy Next.js apps that need a database server, Node runtime, and image storage. OpenNana is the opposite:

- **Zero required dependencies** — plain PHP (built-in server) + Python stdlib. No Composer, no npm, no framework. (Only the optional traffic panel uses Redis.)
- **CDN-first images** — the repo ships **no images**. The gallery hot-links the original CDN, so a full 20,000-prompt site occupies ~50 MB (just the SQLite index) instead of 20 GB.
- **Millisecond search** — SQLite **FTS5 (trigram)** full-text index over titles, tags and prompt bodies; CJK-friendly.
- **SEO static generation** — one command emits a fully server-rendered `index.html` + `sitemap.xml` + JSON-LD.
- **One-command bootstrap** — scrape → index → serve, or run it in Docker.

### Quick start

```bash
git clone https://github.com/wuxiumu/ChiguaFun.git && cd ChiguaFun

make setup           # create config.php (image_mode=cdn by default)
make smoke           # PHP + HTTP check; no dataset required
make scrape-sample   # pull 20 prompts (no images downloaded — CDN mode)
make index           # build SQLite index + static homepage
make serve           # http://127.0.0.1:8765
# private / LAN:     make serve HOST=0.0.0.0
```

Want the whole site? `make scrape` (≈20k prompts, images stay on the CDN) then `make index`.

Before `make index`, the site still starts and explains that the index is missing (`503 index_not_ready` on `/api/*`). Set `site_origin` in `config.php` to the public URL before generating sitemap / OG tags. Behind a TLS-terminating proxy, an empty `site_origin` follows `Host` and `X-Forwarded-Proto`.

### Docker

```bash
make docker-build
# prepare ./data first (scrape or fetch), then:
make docker-run      # http://localhost:8765
# optional: copy .env.example to .env first; docker-run passes it with --env-file
```

### Image modes

| Mode | Images | Disk | How |
|---|---|---|---|
| **`cdn`** (default) | hot-linked from the source CDN | ~50 MB total | `scrape.py --cdn-only`, no download |
| **`oss`** | relative paths joined to `oss_base` (optional Aliyun image process) | images stay on your bucket | set `image_mode` + `oss_base` |
| **`local`** | downloaded + server-generated thumbnails | ~20 GB | `scrape.py` (downloads), `make thumbs` |

Switch via `config.php → image_mode`. In `cdn` mode you can also rewrite the image host to your own CDN/reverse-proxy with `cdn_replace` + `cdn_base`.

### Configuration (`config.php`)

Copy `config.example.php` → `config.php` (git-ignored). Every key has a sane default, so it runs even without one.

| Key | Default | Purpose |
|---|---|---|
| `site_origin` | `''` (auto-detect) | Canonical/OG/sitemap base URL — **set your real domain in production** |
| `image_mode` | `cdn` | `cdn` = hot-link originals · `local` = downloaded + thumbnails |
| `cdn_replace` / `cdn_base` | `''` | Rewrite image host to your own CDN/proxy |
| `data_cdn` | `''` | URL of a `data.tar.gz` for `make data` (restore dataset without scraping) |
| `cors_origin` | `*` | CORS for `/api/*`; set your domain to lock down, `''` to disable |
| `oss_base` | `''` | Resource root for `image_mode=oss`, no trailing slash. Example shape: `https://img.example.com/uploads/banana` |
| `watermark_text` | `AI 生成` | CSS watermark on images; `''` turns it off |
| `clarity_id` / `la51_id` | `''` | Optional analytics IDs. Leave empty so a clone does not report into someone else's account |
| `seo_title` / `seo_description` / `seo_keywords` | brand defaults | Homepage TDK; `{count}` is replaced with the indexed total. Detail pages auto-generate title/description (the **prompt text** becomes the meta description) and merge these brand keywords |

Optional process environment (Redis and the traffic-panel clear password) lives in [`.env.example`](.env.example), not in `config.php`. Copy it to `.env` (git-ignored). `api/tj.php` reads that file; a real environment variable with the same name wins. There is **no default clear password** — `TRAFFIC_CLEAR_PWD` must be set or the clear action stays disabled.

### API

All endpoints return JSON and honour `cors_origin`.

| Endpoint | Params | Returns |
|---|---|---|
| `GET /api/list.php` | `page, limit, model, q` | paginated cards + model facets |
| `GET /api/search.php` | `q` (required), `page, limit, model` | FTS5 full-text search |
| `GET /api/detail.php` | `slug` | full prompt body, images, videos, meta |
| `GET /api/random.php` | `n` (1–60), `model` | 🎲 random prompts ("I'm feeling lucky") |
| `GET /api/models.php` | – | model distribution + media counts |
| `GET /api/stats.php` | – | totals + top-50 tag cloud |

> **Optional:** `api/tj.php` is a lightweight traffic panel (today/total PV & unique IP). It uses **Redis** if available and degrades to zero silently otherwise — the core gallery has **no required dependencies**. Configure via `.env.example`: `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `TRAFFIC_CLEAR_PWD`. Clear stays off until `TRAFFIC_CLEAR_PWD` is set.

<details><summary>Example: <code>/api/random.php?n=2</code></summary>

```json
{
  "count": 2,
  "model": "",
  "items": [
    {
      "id": 13606, "slug": "...", "title": "...", "model": "ChatGPT",
      "media_type": "image",
      "cover": "https://img.opennana.com/prompts/assets/.../...-1.jpg",
      "reviewed_at": "2026-04-...", "tags": ["cinematic", "portrait"],
      "url": "/index.php?p=..."
    }
  ]
}
```
</details>

### Project layout

```
opennana/
├── index.php            detail page + legacy/debug view (SEO + JSON-LD)
├── router.php           built-in-server router (cache headers + gzip + ETag)
├── thumb.php            on-demand thumbnail (local mode only)
├── lib.php              data-access layer (SQLite) + image-URL resolver
├── lib_md.php           Markdown/frontmatter parser + config loader
├── config.example.php   config template (copy to config.php)
├── .env.example         optional Redis / traffic-panel env (copy to .env)
├── api/                 JSON API: list · search · detail · random · models · stats (+ tj.php traffic panel)
├── assets/              css · js · img (logo)
├── scripts/
│   ├── scrape.py        crawler (Python stdlib; --cdn-only recommended)
│   ├── build_index.php  data/*.md → SQLite (FTS5) index
│   ├── gen_static.php   DB → index.html + sitemap.xml + robots.txt
│   ├── gen_pages.php    DB → p/<slug>.html static detail pages (SEO/hreflang)
│   ├── make_thumbs.php  pre-generate thumbnails (local mode)
│   ├── fetch_data.php   restore data/ from data_cdn
│   ├── watch.php        incremental daemon
│   ├── sync.sh          one-shot scrape → index
│   └── smoke.sh         start check (`make smoke`)
├── Dockerfile · docker-entrypoint.sh · Makefile
├── data/   (git-ignored) one .md per prompt
├── images/ (git-ignored) originals — local mode only
├── thumbs/ (git-ignored) thumbnail cache — local mode only
├── p/      (git-ignored) static detail pages — `make pages`
└── cache/  (git-ignored) SQLite index + memoization
```

### Data source & scraping

The gallery reads the same public, auth-free endpoints the upstream front-end uses:

- List `GET https://api.opennana.com/api/prompts?page=N&limit=L&sort=reviewed_at&order=DESC`
- Detail `GET https://api.opennana.com/api/prompts/{slug}` (must query by **slug**, not id)

Two gotchas: the API host is `api.opennana.com` (the main domain 404s on `/api/*`), and requests need a `Referer`/`Origin` header. `.state.json` tracks completed items so an interrupted scrape resumes without duplicates.

### Performance notes

- **FTS5 trigram** search: millisecond queries at 20k rows, with a `LIKE` fallback for <3-char terms and FTS edge cases.
- **WAL** mode: indexing and request-serving don't block each other.
- **Memoized aggregates** (model/tag counts, totals) with a 15–300 s TTL.
- **Static first paint**: `index.html` ships the first 36 cards server-rendered; JS hydrates paging/search.
- **gzip + long-cache + ETag 304** via `router.php`; lazy-loaded images with fade-in and broken-image fallback.

### Third-party credits

- [`qrcode-generator`](https://github.com/kazuhikoarase/qrcode-generator) (MIT) — bundled at `assets/js/qrcode.js`, draws the poster/share QR codes locally (no network).
- Everything else is hand-rolled vanilla PHP/JS/CSS — **no framework, no build step**.

### Static site & SEO

- `make pages` renders **every detail page as static HTML** (`p/<slug>.html`, git-ignored) — zero PHP at request time, fully crawlable, ~20k pages in ~1 min.
- Each static page ships complete TDK (the **prompt body becomes the meta description**), self-referencing canonical, `hreflang` (zh-CN / en / x-default — one URL serves both languages via the prompt tabs), Open Graph / Twitter cards, and JSON-LD `CreativeWork`.
- Homepage `index.html` + `sitemap.xml` / `sitemap-items.xml` / `robots.txt` come from `make static`; homepage TDK is configurable (`seo_*`, `{count}` placeholder).
- Detail URLs are pretty + static: `/p/<slug>.html`. If a static page is missing, the router falls back to the dynamic renderer, so links never 404.

### ⚖️ Disclaimer

This repository contains **engine code only** — no prompt data, no images. Data is fetched at run time by `scrape.py` from publicly accessible endpoints. All prompts and images belong to their original authors and [opennana.com](https://opennana.com); this project is for **learning and personal self-hosting**. Please respect the upstream site's terms and rate-limit your scraping. Remove your instance if requested.

---

## 🇨🇳 中文

> **2 万+ AI 提示词自托管画廊**：CDN 出图、毫秒搜索、可静态 SEO，可按品牌白标交付。  
> **定制 / 合作：** 微信 **qingbao199101**（备注：提示词站）｜邮箱 [wuxiumu@163.com](mailto:wuxiumu@163.com)

### 这是什么

把 [opennana.com](https://opennana.com/awesome-prompt-gallery) 的 2 万+ 条 AI 提示词抓下来，用 Markdown 管理、SQLite 建索引、PHP 渲染成一个可自托管的画廊站点。**仓库只含引擎代码，不含任何图片与数据**，图片直连 CDN，clone 即用。

### 亮点

- **零第三方依赖**：纯 PHP（内置服务器）+ Python 标准库，不用 Composer / npm / 框架。
- **图片走 CDN**：整站 2 万条只占 ~50 MB（一个 SQLite 索引），而不是 20 GB 图片。
- **毫秒级全文检索**：SQLite **FTS5(trigram)**，对中文子串友好，覆盖标题/标签/提示词正文。
- **SEO 静态化**：一条命令生成服务端渲染的 `index.html` + `sitemap.xml` + JSON-LD。
- **一键起步 / Docker**：抓取 → 建索引 → 起服务，或 `make docker-run`。

### 快速开始

```bash
git clone https://github.com/wuxiumu/ChiguaFun.git && cd ChiguaFun
make setup           # 生成 config.php（默认 image_mode=cdn）
make smoke           # 检查 PHP 与 HTTP，不需要数据集
make scrape-sample   # 抓 20 条样本（CDN 模式，不下载图片）
make index           # 建 SQLite 索引 + 静态首页
make serve           # 打开 http://127.0.0.1:8765
# 私有/局域网：    make serve HOST=0.0.0.0
```

要全站：`make scrape`（≈2 万条，图片留在 CDN）再 `make index`。
若你已把数据集打包上传到自己的 CDN，配好 `data_cdn` 后 `make data` 直接还原，无需抓取。
还没建索引时站点也能打开，页面会说明下一步；`/api/*` 返回 `503 index_not_ready`。生成 sitemap / 分享链接前，把 `config.php` 的 `site_origin` 写成对外域名。反代终结 TLS 且 `site_origin` 留空时，会跟着 `Host` 和 `X-Forwarded-Proto`。

### 图片模式

| 模式 | 图片 | 磁盘 | 用法 |
|---|---|---|---|
| **`cdn`**（默认） | 直连源站 CDN | 整站 ~50 MB | `scrape.py --cdn-only`，不下载 |
| **`oss`** | 相对路径拼 `oss_base`（可选阿里云图片处理） | 图片在自己的桶上 | `config.php` 设置 `image_mode` 与 `oss_base` |
| **`local`** | 下载原图 + 服务端缩略图 | ~20 GB | `scrape.py`（下载）+ `make thumbs` |

改 `config.php → image_mode` 切换。cdn 模式下还能用 `cdn_replace` + `cdn_base` 把图片域名换成自己的图床/反代。

### 配置、API、目录结构、数据来源、性能

见上方 [English](#-english) 各节，内容一致（配置项、API 表、目录树、性能优化、免责声明）。

### 命令速查（`make help`）

| 命令 | 作用 |
|---|---|
| `make setup` | 生成 `config.php` |
| `make smoke` | 语法检查并请求首页与 `/api/list.php`（不抓数据） |
| `make scrape` / `scrape-sample` | 抓全站 / 抓 20 条样本（CDN 模式） |
| `make data` | 从 `data_cdn` 下载数据包还原 `data/` |
| `make index` | 建/增量更新索引（自动生成静态页） |
| `make static` | 重新生成首页 index.html + sitemap |
| `make pages` | 生成全部静态详情页 `p/<slug>.html`（SEO/中英 hreflang/提示词进描述） |
| `make thumbs` | 预生成缩略图（仅 local 模式） |
| `make serve` | 启动内置服务器（默认 `127.0.0.1`；对外用 `HOST=0.0.0.0`） |
| `make sync` / `watch` | 一键同步 / 增量守护 |
| `make docker-build` / `docker-run` | 构建 / 运行容器 |
| `make clean` / `clean-all` | 清理缓存 / 清理全部生成物 |

### ⚖️ 免责声明

本仓库**仅含引擎代码**，不含任何提示词数据与图片；数据在运行时由 `scrape.py` 从公开接口获取。所有提示词与图片版权归原作者及 [opennana.com](https://opennana.com) 所有，本项目仅供**学习与个人自托管**。请遵守源站条款、控制抓取频率；如收到下架要求请及时移除实例。

---

<div align="center">

**If this saves you from hosting 20 GB of images, give it a ⭐ — it really helps.**

如果这个项目帮你省下了托管 20 GB 图片的麻烦，点个 ⭐ 就是最大的支持。

MIT © 2026 OpenNana Prompt Gallery contributors

</div>

---



## SEO 字段预留（产品页 HTML 统一生成时用）

| 字段 | 用途 |
|---|---|
| `seo_title` | `<title>` / OG title |
| `seo_description` | meta description |
| `canonical_url` | 规范 URL |
| `og_image` | 分享图 |
| `robots` | 如 `index,follow` |
| `json_ld_type` | SoftwareApplication / WebPage / FAQPage |
| `hreflang` | zh-CN / en |
| `site_name` | 白标品牌名 |

> 说明：**GEO ≠ 地理定位**。GEO = Generative Engine Optimization（生成式引擎优化），见下一节。

## GEO（生成式引擎优化）

面向 ChatGPT / Perplexity / Gemini 等生成式答案引擎的**可引用结构**：中文为主、双语关键词、事实摘要前置、FAQ 收尾。

### 关键词（中英）

- 中文：AI提示词画廊, 自托管提示词库, Nano Banana, 提示词搜索, 白标画廊
- English: AI prompt gallery, self-hosted prompts, full-text search, CDN images, white-label

### 事实摘要

- 产品：自托管 AI 图像/视频提示词画廊（PHP + SQLite FTS5，图片可走 CDN）。
- 适合：要自建提示词站的个人、工作室、训练营、导航站。
- 交付：可运行引擎；可定制白标、私有词库、CDN/广告位。
- 不做：仓库不含上游提示词与图片数据包；不提供无授权倒卖数据。

### FAQ（便于生成式引擎引用）

**Q：这是什么？**  
A：见上方事实摘要「产品」一行。

**Q：适合谁付钱定制？**  
A：见「适合」；具体加微信 `qingbao199101` 备注项目名。

**Q：开源仓库是否已含全部数据/密钥？**  
A：默认不含 API Key；数据/图片是否入库以各项目说明为准。

**Q：是否自动发帖或自动运营第三方平台？**  
A：内容工厂类项目明确不自动发帖；其它项目以 README「不做」边界为准。


## 下一步 / 深优化

下一轮可执行建议（Demo / 安全 / 白标 / 交付包）见 [`docs/OPTIMIZATION.md`](docs/OPTIMIZATION.md)。轻量加固已完成；深优化按文档验收后再改代码。

## 定制合作 / Custom

私有化部署、品牌白标、私有提示词库导入、CDN/广告位配置。加微信备注「提示词画廊」。


---

## 联系方式

定制、演示或合作：

- 邮箱：[wuxiumu@163.com](mailto:wuxiumu@163.com)
- 微信：**qingbao199101**（添加请备注来意）

### 赞助支持

如果内容对你有帮助，欢迎自愿赞助。

| 微信赞赏码 | 支付宝收款码 |
|---|---|
| ![微信赞赏码](https://chiguashentan-test.oss-cn-beijing.aliyuncs.com/wxprogram/zan/20261002185236_31_227.png) | ![支付宝收款码](https://chiguashentan-test.oss-cn-beijing.aliyuncs.com/wxprogram/zan/20261002185309_32_227.jpg) |
