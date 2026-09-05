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

Most "prompt galleries" are either closed SaaS or heavy Next.js apps that need a database server, Node runtime, and image storage. OpenNana is the opposite:

- **Zero required dependencies** — plain PHP (built-in server) + Python stdlib. No Composer, no npm, no framework. (Only the optional traffic panel uses Redis.)
- **CDN-first images** — the repo ships **no images**. The gallery hot-links the original CDN, so a full 20,000-prompt site occupies ~50 MB (just the SQLite index) instead of 20 GB.
- **Millisecond search** — SQLite **FTS5 (trigram)** full-text index over titles, tags and prompt bodies; CJK-friendly.
- **SEO static generation** — one command emits a fully server-rendered `index.html` + `sitemap.xml` + JSON-LD.
- **One-command bootstrap** — scrape → index → serve, or run it in Docker.

### Quick start

```bash
git clone https://github.com/wuxiumu/ChiguaFun.git && cd opennana

make setup           # create config.php (image_mode=cdn by default)
make scrape-sample   # pull 20 prompts (no images downloaded — CDN mode)
make index           # build SQLite index + static homepage
make serve           # http://127.0.0.1:8765
```

Want the whole site? `make scrape` (≈20k prompts, images stay on the CDN) then `make index`.

### Docker

```bash
make docker-build
# prepare ./data first (scrape or fetch), then:
make docker-run      # http://localhost:8765
```

### Image modes

| Mode | Images | Disk | How |
|---|---|---|---|
| **`cdn`** (default) | hot-linked from the source CDN | ~50 MB total | `scrape.py --cdn-only`, no download |
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

> **Optional:** `api/tj.php` is a lightweight traffic panel (today/total PV & unique IP). It uses **Redis** if available and degrades to zero silently otherwise — the core gallery has **no required dependencies**. Configure via env: `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `TRAFFIC_CLEAR_PWD` (default clear-password is `123456` — **change it in production**).

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
├── api/                 JSON API: list · search · detail · random · models · stats (+ tj.php traffic panel)
├── assets/              css · js · img (logo)
├── scripts/
│   ├── scrape.py        crawler (Python stdlib; --cdn-only recommended)
│   ├── build_index.php  data/*.md → SQLite (FTS5) index
│   ├── gen_static.php   DB → index.html + sitemap.xml + robots.txt
│   ├── make_thumbs.php  pre-generate thumbnails (local mode)
│   ├── fetch_data.php   restore data/ from data_cdn
│   ├── watch.php        incremental daemon
│   └── sync.sh          one-shot scrape → index
├── Dockerfile · docker-entrypoint.sh · Makefile
├── data/   (git-ignored) one .md per prompt
├── images/ (git-ignored) originals — local mode only
├── thumbs/ (git-ignored) thumbnail cache — local mode only
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

### ⚖️ Disclaimer

This repository contains **engine code only** — no prompt data, no images. Data is fetched at run time by `scrape.py` from publicly accessible endpoints. All prompts and images belong to their original authors and [opennana.com](https://opennana.com); this project is for **learning and personal self-hosting**. Please respect the upstream site's terms and rate-limit your scraping. Remove your instance if requested.

---

## 🇨🇳 中文

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
git clone https://github.com/wuxiumu/ChiguaFun.git && cd opennana
make setup           # 生成 config.php（默认 image_mode=cdn）
make scrape-sample   # 抓 20 条样本（CDN 模式，不下载图片）
make index           # 建 SQLite 索引 + 静态首页
make serve           # 打开 http://127.0.0.1:8765
```

要全站：`make scrape`（≈2 万条，图片留在 CDN）再 `make index`。
若你已把数据集打包上传到自己的 CDN，配好 `data_cdn` 后 `make data` 直接还原，无需抓取。

### 图片模式

| 模式 | 图片 | 磁盘 | 用法 |
|---|---|---|---|
| **`cdn`**（默认） | 直连源站 CDN | 整站 ~50 MB | `scrape.py --cdn-only`，不下载 |
| **`local`** | 下载原图 + 服务端缩略图 | ~20 GB | `scrape.py`（下载）+ `make thumbs` |

改 `config.php → image_mode` 切换。cdn 模式下还能用 `cdn_replace` + `cdn_base` 把图片域名换成自己的图床/反代。

### 配置、API、目录结构、数据来源、性能

见上方 [English](#-english) 各节，内容一致（配置项、API 表、目录树、性能优化、免责声明）。

### 命令速查（`make help`）

| 命令 | 作用 |
|---|---|
| `make setup` | 生成 `config.php` |
| `make scrape` / `scrape-sample` | 抓全站 / 抓 20 条样本（CDN 模式） |
| `make data` | 从 `data_cdn` 下载数据包还原 `data/` |
| `make index` | 建/增量更新索引（自动生成静态页） |
| `make thumbs` | 预生成缩略图（仅 local 模式） |
| `make serve` | 启动内置服务器 |
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
