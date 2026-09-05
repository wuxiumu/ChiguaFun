#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
OpenNana 提示词图库抓取器
https://opennana.com/awesome-prompt-gallery

数据源（未公开但前端直接调用，无需鉴权）:
  - 列表: GET https://api.opennana.com/api/prompts?page=N&limit=L&sort=reviewed_at&order=DESC
  - 详情: GET https://api.opennana.com/api/prompts/{slug}

产出:
  data/<id>-<slug>.md      每条提示词一个 md 文件（YAML frontmatter + 正文）
  images/<id>-<n>.<ext>    原图单独目录（仅非 cdn 模式）

用法（脚本在 scripts/，产出写到项目根 data/、images/）:
  python3 scripts/scrape.py --limit 3 --cdn-only   # 测试：3 条，图片走 CDN 不下载（推荐）
  python3 scripts/scrape.py --all --cdn-only --page-size 100 --workers 24   # 全站，CDN 模式
  python3 scripts/scrape.py --all                  # 全站，下载原图到 images/（local 模式）
  python3 scripts/scrape.py --limit 200 --workers 4
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import urllib.error
from concurrent.futures import ThreadPoolExecutor, as_completed

API_BASE = "https://api.opennana.com"
SITE_URL = "https://opennana.com/awesome-prompt-gallery"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
    ),
    "Referer": SITE_URL,
    "Origin": "https://opennana.com",
    "Accept": "application/json, text/plain, */*",
}

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))   # scripts/
BASE_DIR = os.path.dirname(SCRIPT_DIR)                    # 项目根
DATA_DIR = os.path.join(BASE_DIR, "data")
IMAGE_DIR = os.path.join(BASE_DIR, "images")
STATE_FILE = os.path.join(BASE_DIR, ".state.json")


# --------------------------------------------------------------------------
# HTTP
# --------------------------------------------------------------------------
def http_get(url, retries=3, timeout=30, binary=False):
    """带重试的 GET。返回 (ok, bytes_or_None, err)"""
    for attempt in range(1, retries + 1):
        try:
            req = urllib.request.Request(url, headers=HEADERS)
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                data = resp.read()
                return True, data, None
        except urllib.error.HTTPError as e:
            if e.code in (403, 429) and attempt < retries:
                time.sleep(2 * attempt)
                continue
            return False, None, f"HTTP {e.code}"
        except Exception as e:  # noqa: BLE001
            if attempt < retries:
                time.sleep(2 * attempt)
                continue
            return False, None, str(e)
    return False, None, "retry exhausted"


def api_get(path):
    ok, raw, err = http_get(API_BASE + path)
    if not ok:
        return None, err
    try:
        return json.loads(raw.decode("utf-8")), None
    except Exception as e:  # noqa: BLE001
        return None, f"JSON 解析失败: {e}"


# --------------------------------------------------------------------------
# 工具
# --------------------------------------------------------------------------
def y(v):
    """把 Python 值转成合法的 YAML 标量/行内结构（JSON 是 YAML 的子集）。"""
    if v is None:
        return "null"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, (list, dict)):
        return json.dumps(v, ensure_ascii=False)
    return json.dumps(str(v), ensure_ascii=False)


def safe_filename(s, maxlen=60):
    s = re.sub(r"[\\/:*?\"<>|\n\r\t]+", "_", str(s or "")).strip()
    s = re.sub(r"\s+", " ", s)
    return s[:maxlen].strip(" .") or "untitled"


def url_ext(url):
    path = urllib.parse.urlparse(url).path
    ext = os.path.splitext(path)[1].lower()
    return ext if ext in (".jpg", ".jpeg", ".png", ".webp", ".gif", ".mp4", ".webm") else ".jpg"


def load_state():
    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE, encoding="utf-8") as f:
                return json.load(f)
        except Exception:  # noqa: BLE001
            pass
    return {"done": [], "failed": {}}


def save_state(state):
    with open(STATE_FILE, "w", encoding="utf-8") as f:
        json.dump(state, f, ensure_ascii=False, indent=1)


# --------------------------------------------------------------------------
# 抓取
# --------------------------------------------------------------------------
def fetch_list(page=1, limit=20, **extra):
    params = {"page": str(page), "limit": str(limit), "sort": "reviewed_at", "order": "DESC"}
    params.update({k: v for k, v in extra.items() if v is not None})
    qs = urllib.parse.urlencode(params).replace("+", "%20")
    data, err = api_get("/api/prompts?" + qs)
    if err or not data or data.get("status") != 200:
        return None, err or data.get("msg")
    return data["data"], None


def fetch_detail(slug):
    data, err = api_get("/api/prompts/" + urllib.parse.quote(slug, safe=""))
    if err:
        return None, err
    if not data or data.get("status") != 200:
        return None, (data or {}).get("msg", "未知错误")
    return data["data"], None


def download_media(url, dest):
    """下载图片/视频，已存在则跳过。返回 (相对路径, 是否新下载)"""
    if not url:
        return None, False
    if os.path.exists(dest) and os.path.getsize(dest) > 0:
        return dest, False
    ok, raw, err = http_get(url, retries=2, timeout=60, binary=True)
    if not ok or not raw:
        return None, False
    tmp = dest + ".part"
    with open(tmp, "wb") as f:
        f.write(raw)
    os.replace(tmp, dest)
    return dest, True


# --------------------------------------------------------------------------
# Markdown 生成
# --------------------------------------------------------------------------
def build_markdown(d, local_images):
    title = d.get("title") or "未命名"
    slug = d.get("slug") or ""

    front = [
        "---",
        f"id: {y(d.get('id'))}",
        f"slug: {y(slug)}",
        f"title: {y(title)}",
        f"description: {y(d.get('description'))}",
        f"model: {y(d.get('model'))}",
        f"media_type: {y(d.get('media_type'))}",
        f"source_name: {y(d.get('source_name'))}",
        f"source_url: {y(d.get('source_url'))}",
        f"tags: {y(d.get('tags') or [])}",
        f"access_type: {y(d.get('access_type'))}",
        f"paid_points: {y(d.get('paid_points'))}",
        f"view_count: {y(d.get('view_count'))}",
        f"like_count: {y(d.get('like_count'))}",
        f"copy_count: {y(d.get('copy_count'))}",
        f"is_featured: {y(d.get('is_featured'))}",
        f"reviewed_at: {y(d.get('reviewed_at'))}",
        f"created_at: {y(d.get('created_at'))}",
        f"updated_at: {y(d.get('updated_at'))}",
        f"url: {y(SITE_URL + '/' + slug)}",
        f"source_images: {y(d.get('images') or [])}",
        f"video_urls: {y(d.get('video_urls') or [])}",
        "---",
        "",
    ]

    body = [f"# {title}", ""]

    if d.get("description"):
        body += ["> " + str(d["description"]).replace("\n", " ").strip(), ""]

    meta = []
    if d.get("model"):
        meta.append(f"- **模型**：{d['model']}")
    if d.get("media_type"):
        meta.append(f"- **类型**：{d['media_type']}")
    if d.get("source_name") or d.get("source_url"):
        name = d.get("source_name") or "来源"
        url = d.get("source_url") or ""
        meta.append(f"- **来源**：[{name}]({url})" if url else f"- **来源**：{name}")
    if d.get("tags"):
        meta.append("- **标签**：" + "、".join(map(str, d["tags"])))
    meta.append(f"- **页面**：{SITE_URL}/{slug}")
    body += meta + [""]

    if local_images:
        body += ["## 图片", ""]
        for p in local_images:
            rel = os.path.relpath(p, BASE_DIR)
            body.append(f"![]({rel})")
            body.append("")
    elif d.get("images"):
        body += ["## 图片", ""]
        for u in d["images"]:
            body.append(f"![]({u})")
            body.append("")

    if d.get("video_urls"):
        body += ["## 视频", ""]
        for u in d["video_urls"]:
            body.append(f"- {u}")
        body.append("")

    prompts = d.get("prompts") or []
    if prompts:
        body += ["## 提示词", ""]
        for i, p in enumerate(prompts, 1):
            label = p.get("label") or p.get("type") or f"提示词 {i}"
            body.append(f"### {i}. {label}")
            body.append("")
            body += ["```text", (p.get("text") or "").strip(), "```", ""]

    if d.get("notes"):
        body += ["## 备注", "", str(d["notes"]), ""]
    if d.get("examples"):
        body += ["## 示例", "", str(d["examples"]), ""]

    return "\n".join(front + body)


# --------------------------------------------------------------------------
# 主流程
# --------------------------------------------------------------------------
def collect_items(target, page_size, extra):
    """按需拉取列表，直到凑够 target 条（None=全部）"""
    items, page = [], 1
    total = None
    while True:
        data, err = fetch_list(page, page_size, **extra)
        if err:
            print(f"  [!] 列表第 {page} 页失败: {err}")
            break
        batch = data.get("items") or []
        if total is None:
            total = data.get("pagination", {}).get("total", 0)
            print(f"  站点共 {total} 条记录")
        items += batch
        if target is not None and len(items) >= target:
            items = items[:target]
            break
        if not data.get("pagination", {}).get("has_more") or not batch:
            break
        page += 1
        time.sleep(0.3)
    return items


def process(item, with_images=True):
    pid = item.get("id")
    slug = item.get("slug")
    if not slug:
        return pid, False, "缺少 slug"

    d, err = fetch_detail(slug)
    if err:
        return pid, False, f"详情失败: {err}"

    title = d.get("title") or "untitled"
    fname = f"{pid}-{safe_filename(slug, 80)}.md"
    md_path = os.path.join(DATA_DIR, fname)

    local_images = []
    if with_images:
        for i, url in enumerate(d.get("images") or [], 1):
            dest = os.path.join(IMAGE_DIR, f"{pid}-{i}{url_ext(url)}")
            ok_path, _ = download_media(url, dest)
            if ok_path:
                local_images.append(ok_path)
            time.sleep(0.15)
        for i, url in enumerate(d.get("video_urls") or [], 1):
            dest = os.path.join(IMAGE_DIR, f"{pid}-video{i}{url_ext(url)}")
            download_media(url, dest)
            time.sleep(0.15)

    with open(md_path, "w", encoding="utf-8") as f:
        f.write(build_markdown(d, local_images))

    return pid, True, f"{title}  (图 {len(local_images)} 张)"


def main():
    ap = argparse.ArgumentParser(description="抓取 OpenNana 提示词图库")
    ap.add_argument("--limit", type=int, default=3, help="抓取条数，默认 3")
    ap.add_argument("--all", action="store_true", help="抓取全站")
    ap.add_argument("--page-size", type=int, default=20, help="每页条数，默认 20")
    ap.add_argument("--workers", type=int, default=4, help="并发数，默认 4")
    ap.add_argument("--no-images", action="store_true", help="跳过图片下载")
    ap.add_argument("--cdn-only", action="store_true",
                    help="CDN 模式（推荐）：不下载图片，md 直接引用 CDN 原图，配合 config image_mode=cdn")
    ap.add_argument("--search", type=str, default=None, help="关键词过滤")
    ap.add_argument("--media-type", choices=["image", "video"], default=None)
    args = ap.parse_args()

    # cdn-only / no-images 都不下载图片；正文与 frontmatter 均引用 CDN 原图
    with_images = not (args.no_images or args.cdn_only)

    os.makedirs(DATA_DIR, exist_ok=True)
    if with_images:
        os.makedirs(IMAGE_DIR, exist_ok=True)

    target = None if args.all else args.limit
    extra = {"search": args.search, "media_type": args.media_type}

    print(f"[1/3] 拉取列表… (目标 {target or '全部'} 条)")
    items = collect_items(target, args.page_size, extra)
    print(f"      拿到 {len(items)} 条")

    state = load_state()
    done = set(state.get("done", []))
    todo = [it for it in items if it.get("id") not in done]
    if len(items) != len(todo):
        print(f"      跳过已完成的 {len(items) - len(todo)} 条")

    if not todo:
        print("没有新条目，任务结束。")
        return

    mode_txt = "抓取详情 + 下载图片" if with_images else "抓取详情（CDN 模式，不下载图片）"
    print(f"[2/3] {mode_txt}… ({len(todo)} 条，并发 {args.workers})")
    ok_n = fail_n = 0
    t0 = time.time()

    def img_gb():
        if not os.path.isdir(IMAGE_DIR):
            return 0.0
        try:
            return sum(
                os.path.getsize(os.path.join(IMAGE_DIR, f))
                for f in os.listdir(IMAGE_DIR)
            ) / 1073741824
        except OSError:
            return 0.0

    with ThreadPoolExecutor(max_workers=args.workers) as ex:
        futs = {ex.submit(process, it, with_images): it for it in todo}
        for i, fut in enumerate(as_completed(futs), 1):
            pid, ok, msg = fut.result()
            if ok:
                ok_n += 1
                done.add(pid)
                state.setdefault("failed", {}).pop(str(pid), None)
            else:
                fail_n += 1
                state.setdefault("failed", {})[str(pid)] = msg

            if i % 25 == 0 or i == len(todo):
                el   = max(time.time() - t0, 0.1)
                rate = i / el
                eta  = (len(todo) - i) / rate if rate > 0 else 0
                print(
                    f"  {i}/{len(todo)} ({i / len(todo) * 100:5.1f}%) | "
                    f"{rate * 60:5.1f} 条/分 | 剩余 {eta / 60:5.1f} 分 | "
                    f"图片 {img_gb():5.2f} GB | 失败 {fail_n}"
                    + (f" | 最近失败 id={pid}: {msg[:60]}" if fail_n and not ok else ""),
                    flush=True,
                )

            if i % 200 == 0:
                save_state({"done": sorted(done), "failed": state.get("failed", {})})

    state["done"] = sorted(done)
    save_state(state)

    print(f"[3/3] 完成：成功 {ok_n} 条，失败 {fail_n} 条，耗时 {time.time() - t0:.1f}s")
    print(f"      md 目录: {DATA_DIR}")
    print(f"      图片目录: {IMAGE_DIR}")


if __name__ == "__main__":
    sys.exit(main())
