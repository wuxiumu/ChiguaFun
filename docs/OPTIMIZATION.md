# ChiguaFun（OpenNana）· 深优化建议（文档先行）

> 与父仓 `docs/OPTIMIZATION-NEXT.md` Top1 对齐。  
> 轻量加固：✅ C1 PR（去默认口令 / `make smoke` / 空站说明）。**下列留给下一轮代码，本文件先定验收。**

## 现状对照

| 项 | 现状 |
|---|---|
| 配置 | `config.example.php` → `make setup` 生成 `config.php`（不入库） |
| 启动 | `make` / PHP 内置服务；可选 Docker |
| Demo | README Live Demo 仍为占位；首页截图注释未启用 |
| 数据 | 仓库不含提示词包与图片；CDN 模式开箱 |

## 建议（可执行）

| # | 建议 | 类型 | 验收 |
|---|---|---|---|
| 1 | 补真实 Live Demo URL + 首页截图（取消 README 顶部注释，CDN 托管约 1200px 图） | Demo/文档 | README 顶部可点 Demo，有图 |
| 2 | `make smoke` 扩展：无 `data/` 时断言「空站说明」页 HTTP 200 且含固定文案 | 可运行 | 本地一键绿；可进 CI |
| 3 | 白标：站点名 / 主题色 / CDN 前缀集中到 `config.php`（或 `config/brand.php`），模板只读配置 | 定制 | 改 ≤3 处即可换皮 |
| 4 | 管理端首次启动强制改密：未改密禁止写操作（已去硬编码，再加引导） | 安全 | 首次请求写 API 返回改密引导 |
| 5 | 客户交付包：`make dist-client` 只打静态 + SQLite 索引，不含爬虫密钥与 `scripts/scrape.py` 凭据 | 定制 | 产物可独立部署 |
| 6 | FAQ/GEO：中英各一段事实摘要对齐（产品 / 适合 / 交付 / 不做） | 文档 | 便于被 AI 引用 |

## 建议落地顺序

1 → 2 → 4 → 3 → 5 → 6（先 Demo 可看、再安全、再白标交付）。

## 定制联系

微信 **qingbao199101**（备注：提示词站）· [wuxiumu@163.com](mailto:wuxiumu@163.com)
