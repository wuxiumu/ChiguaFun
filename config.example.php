<?php
/**
 * OpenNana 提示词库 - 配置模板
 *
 * 用法：
 *   cp config.example.php config.php   # 然后按需修改 config.php
 *
 * config.php 不提交到 git（已在 .gitignore）。
 * 不创建 config.php 也能跑：所有键都有内置默认值（image_mode=cdn，开箱即用）。
 */

return [
    // 站点对外的源，用于 SEO canonical / og:url / sitemap / 海报分享 / 绝对地址。
    // 留空时：Web 请求自动用当前 Host，CLI（如 gen_static.php）回退到 http://localhost:8765。
    // 生产环境务必填真实域名（改完后执行 make static 重新生成 index.html / sitemap）。
    'site_origin' => 'https://banana.chiguashentan.com',

    // 图片模式（库内双轨：images=本地路径 + cdn_images=源站 CDN；改这一项即可切换）：
    //   'cdn'   —— md frontmatter 的 source_images（第三方 CDN 原图）
    //   'oss'   —— 本地相对路径 + oss_base，带 x-oss-process 缩放/webp
    //   'local' —— 本地 images/ + thumbs/
    // 动态预览改完即生效；静态 HTML 需再执行 make static && make pages。
    'image_mode'  => 'cdn',

    // 阿里云 OSS/CDN 资源根（仅 oss 模式），无尾斜杠。
    // 绑定自定义 CDN 域名后改成 https://img.your-cdn.com/uploads/banana 即可。
    'oss_base'    => 'https://chiguashentan-test.oss-cn-beijing.aliyuncs.com/uploads/banana',
    'oss_process' => true,   // true：追加 ?x-oss-process=image/resize…/format,webp/quality,q_xx
    'oss_format'  => 'webp', // webp | jpg | png | ''（原格式）
    'oss_quality' => 75,     // 1–100；0 不传 quality

    // cdn 模式可选改写：把 source_images 的 cdn_replace 前缀换成 cdn_base。
    //   例：'cdn_replace' => 'https://img.opennana.com', 'cdn_base' => 'https://cdn.your-domain.com'
    'cdn_replace' => '',
    'cdn_base'    => '',

    // 数据包 CDN（可选）：一个 data.tar.gz 的直链，`make data` 会下载并解压到 data/。
    // OSS 上若是解压后的 data/ 目录而非压缩包，请继续用本地 data/ 构建索引。
    'data_cdn'    => '',

    // API 跨域：允许浏览器跨域调用 /api/*。默认 '*'（开源演示方便）；
    // 生产可收紧为你的域名。设为 '' 则不下发 CORS 头（同源）。
    'cors_origin' => 'https://banana.chiguashentan.com',

    // 图片水印文字（用 CSS 叠加在所有图片底部居中处，白色半透明）。
    // 设为 '' 关闭水印。改成任意文案即可自定义，如 'AI 生成 · 仅供演示'。
    'watermark_text' => 'AI 生成',

    // ---- SEO TDK（首页 Title/Description/Keywords；{count} 会替换为收录总数）----
    // 详情页标题/描述仍按条目自动生成（利于长尾），但 keywords 会并入下面的品牌词。
    'seo_title'       => '51chigua 吃瓜提示词库 · {count}+ 条 AI 提示词与生成案例',
    'seo_description' => '51chigua 吃瓜提示词库（🍉 ChiguaNana）收录 {count}+ 条 ChatGPT、Nano Banana、Seedance、Grok、即梦等模型的 AI 图像与视频提示词，含原图与中英文版本，可一键复制、生成分享海报。提示词吃瓜、banana我要吃瓜，每日更新。',
    'seo_keywords'    => '51chigua,吃瓜,提示词吃瓜,nana51chigua,banana51chigua,banana我要吃瓜,51吃瓜,吃瓜网,吃瓜群众,每日吃瓜,吃瓜爆料,AI吃瓜,吃瓜提示词,ChiguaNana,chiguanana 提示词,nano banana 提示词,nano banana prompt,banana 提示词,AI 提示词库,提示词大全,AI 绘画提示词,AI 视频提示词,ChatGPT 提示词,提示词分享,提示词画廊,prompt gallery',

    // 第三方统计（可选，填自己的 ID；留空则完全不加载，开源克隆者不会误报流量）：
    //   clarity_id —— Microsoft Clarity 项目 ID（https://clarity.microsoft.com 后台获取）
    //   la51_id    —— 51.la 统计 ID（https://www.51.la 后台获取，id 与 ck 相同）
    'clarity_id' => '',
    'la51_id'    => '',
];
