# 余白 MARGINALIA

一份每天北京时间 **09:00** 自动汇总的互联网日刊：20 个网站与项目，国内 5 条、国际 5 条新闻。保留 Kami 风格的暖羊皮纸、墨蓝、衬线标题和大留白，但内容由 PHP 在服务器上自动抓取、缓存和切换。

- 不需要 MySQL，内容存在 `cache/issue-YYYY-MM-DD.json`
- 不需要构建步骤，上传即可运行
- 目标环境：VistaPanel / MyOwnFreeHost 一类支持 PHP 的共享主机
- 代码按 PHP 7.4+ 编写，建议 PHP 8.0 以上

## 当前部署

站点已经部署在 **<https://yubai.66ghz.com>**，第 001 期为 2026-10-27。旧地址 `research-agent.my-place.us` 已整体 301 到新域名，路径和查询串都会保留。

| 项目 | 实际情况 |
| --- | --- |
| 主机 | InfinityFree / VistaPanel（账户信息不写进仓库） |
| PHP | 8.4.27，cURL 8.22，SimpleXML / mbstring / OpenSSL 均可用 |
| 执行限制 | `max_execution_time=60`，内存 512M |
| 抓取模式 | **顺序**：主机禁用了 `curl_multi_*`，代码自动降级并共享 40 秒总预算 |
| 首访构建 | 约 12–33 秒（顺序抓取，单个源最多 8 秒）；之后读缓存，首页通常 0.1 秒内 |
| 缓存保护 | `/cache/`、`/config.php`、`/data/*` 均返回 403 |
| HTTPS | 可用，`*.66ghz.com` 通配符证书（ZeroSSL） |
| 页面层 | InfinityFree 有 JS 反爬挑战（`__test` cookie），普通浏览器自动通过；外部 cron 需要带固定 cookie，见下文 |
| 定时任务 | 免费套餐的 VistaPanel “Cron Jobs” 已停用，只能走外部服务 |

> 域名是 2026-10-27 从 `research-agent.my-place.us` 迁到 `yubai.66ghz.com` 的。新域名在 `66ghz.com` 的免费后缀下，和主域共享同一个账户与磁盘，`cache/meta.json` 与 `issue-2026-10-27.json` 已随迁，所以期号仍是第 001 期。

> `index.html` 是最初的静态视觉稿，只用于对照设计，不参与部署。真正要上传的是 `deploy/` 里的内容。

> **仍然存在的限制**：InfinityFree 的 JS 挑战只放行普通浏览器和已识别的爬虫（Googlebot / Bingbot / Feedly 等 UA 能直接拿到完整 HTML），但普通首次访客仍会看到一次跳转，未白名单的 RSS 阅读器也可能拿到挑战页。要彻底解决，需要把渲染结果静态化后迁到 Cloudflare Pages / GitHub Pages；当前代码的 `cache/issue-*.json` 已经可以直接当作构建输入。

根目录的 `deploy.zip` 是 `deploy/` 的干净副本：`cache/` 只保留 `.htaccess`，不含 `meta.json` 和示例期。解压到 `htdocs` 后第一次访问会现场生成第 001 期。

## 目录

```text
deploy/
├── index.php              首页、往期视图、404
├── loading.php            当天首次构建时的过渡页
├── refresh.php            自动 / 手动刷新端点
├── health.php             主机与来源诊断
├── feed.php               RSS 2.0（固定地址 /feed.xml）
├── config.php             站点、来源、配额、刷新密钥
├── lib.php                抓取、解析、缓存、期号逻辑
├── .htaccess              目录保护、固定地址重写、缓存头、安全头
├── assets/
│   ├── style.css
│   ├── app.js             倒计时、自动刷新、随机漫游、筛选
│   ├── og.png             1200×630 分享图
│   └── fonts/             自托管 Newsreader / DM Sans（OFL）
├── cache/                 每日 JSON 缓存（需要写权限）
│   └── .htaccess          禁止直接访问缓存
└── data/
    ├── seed.php           主题与常青项目兜底
    ├── cacert.pem         HTTPS 根证书
    └── .htaccess
```

## 本地预览

在 `deploy/` 目录启动 PHP 内置服务器：

```powershell
cd D:\test\deploy
php -S 127.0.0.1:8080
```

打开 <http://127.0.0.1:8080/>。第一次打开会同步抓取，通常需要 6–20 秒；之后读取缓存，很快。

本地也可以直接检查接口：

```powershell
curl.exe "http://127.0.0.1:8080/refresh.php?auto=1"
curl.exe "http://127.0.0.1:8080/health.php?format=html"
curl.exe "http://127.0.0.1:8080/health.php?probe=1&format=html"
```

## 部署到 VistaPanel

### 1. 上传文件

把 `deploy/` **里面的内容**上传到网站根目录（VistaPanel 常见为 `htdocs`），不要上传成 `htdocs/deploy/...`。

注意：

- FTP 客户端要开启“显示隐藏文件”，确保 `.htaccess`、`cache/.htaccess`、`data/.htaccess` 都上传成功。
- `data/cacert.pem` 用二进制模式上传，不要用文本模式。
- `cache/` 目录必须存在。可以暂时保留一个空目录，首次访问时程序会写入 JSON。
- 部署后访问一次 `/cache/`，确认被拒绝；如果能看到文件列表，说明主机没有执行 `.htaccess`，需要改用主机面板的目录保护，或换用 Apache/LiteSpeed 主机。InfinityFree 会返回 403 而不是 404，同样算受保护。

### 2. 设置目录权限

`cache/` 需要 PHP 可写：

1. 在文件管理器里右键 `cache/` → 权限；
2. 先试 `755`，如果页面提示“还没有构建成功”，再试 `775`；
3. 部分主机需要临时用 `777` 才能写入，确认能写后改回 `755` 或 `775`。

不要把整站设成 `777`。`config.php`、`lib.php` 已由 `.htaccess` 限制直接访问。

### 3. 第一次打开

访问：

```text
https://yubai.66ghz.com/
```

第一位访客会触发同步构建，通常 8–15 秒。页面顶部出现“自动汇总 · 抓取于 …”就说明成功；正常应看到 20 个项目、国内 5 条、国际 5 条。

如果超过 30 秒仍是空白或 500：

- 打开 `health.php?format=html` 看 PHP 版本、cURL、SimpleXML 和 `cache` 写入状态；
- 打开 `health.php?probe=1&format=html` 看主机是否能访问各来源（这台主机约 7 秒）；
- 检查 `cache/error.log` 和主机错误日志。
- 如果错误是 `Call to undefined function curl_multi_exec()`，说明代码是旧版本；当前版本会自动降级为顺序抓取。

### 4. 设置刷新密钥

编辑 `config.php`：

```php
'refresh' => [
    'key' => '换成一串足够长的随机字符',
],
```

可以用下面的命令生成：

```powershell
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

密钥的作用：

- `refresh.php?key=...`：给 cron 用的正常刷新；当天已有内容时直接返回，不重复抓取；
- `refresh.php?key=...&force=1`：强制重抓当天；
- **密钥为空时，`force` 会被关闭**，避免陌生人反复触发抓取；自动刷新 `refresh.php?auto=1` 不受影响。

改完密钥后，打开 `health.php?format=html`，确认“refresh key”显示“已设置”。

### 5. 定时任务（推荐）

页面本身会在访客打开后自动检查并补建当天内容。为了在 09:00 就把新一期准备好，可以再加一个外部 cron。

这台主机有 InfinityFree 的 JS 反爬挑战，**普通 cron 请求会拿到挑战页而不是 JSON**。挑战页里的常量是固定的，所以带上 `__test` cookie 和浏览器 UA 就能绕过：

- 时间：北京时间每天 **09:00**，即 UTC **01:00**
- URL：`https://yubai.66ghz.com/refresh.php`（设置了 `refresh.key` 时加 `?key=...`）
- 方法：GET
- 自定义请求头：

  ```text
  User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36
  Cookie: __test=587c5a3280af75802e316a1fb0b79d4c
  ```

- 不要每天带 `force=1`；正常刷新就够。

在 [cron-job.org](https://cron-job.org/) 里新建任务，把上面两个请求头填进 “Custom HTTP headers” 即可。用命令行验证：

```powershell
curl.exe -A "Mozilla/5.0 ... Chrome/130.0 Safari/537.36" `
  -H "Cookie: __test=587c5a3280af75802e316a1fb0b79d4c" `
  "https://yubai.66ghz.com/refresh.php"
```

如果哪天 cron 开始拿到一段 JavaScript 而不是 JSON，说明 InfinityFree 换了挑战常量：用浏览器打开一次站点，在开发者工具里复制新的 `__test` 值替换即可。即使 cron 短暂失效，访客打开页面时仍会自动补建当天内容，只是首访会多等几秒。

### 6. 上线后检查

- 首页：20 个项目、10 条新闻、倒计时正常；
- `/issue/昨天的日期/` 和 `?issue=昨天的日期`：能打开往期，顶部出现“往期”提示条；
- `/feed.xml`：返回 RSS 2.0，至少包含最近一期；
- 首页打开 `#cat=build`：只剩“造”类项目，再点一次按钮回到全部；
- 存档搜索框输入一个词：只留下匹配的往期；
- `health.php?format=html`：状态“可以运行”，缓存“可写”，抓取模式显示“顺序”或“并行”；
- `refresh.php?auto=1`：返回 `"built": false`（当天已有内容时）；
- 手机宽度 390 / 320：没有横向滚动，新闻和存档可正常展开。

## 订阅、分享与固定地址

- **RSS**：`/feed.xml`，每期一条，最近 21 天。页面 `<head>` 里也有 `rel="alternate"`，阅读器可以直接发现。
- **固定地址**：`/issue/2026-10-27/`。`?issue=2026-10-27` 与旧的 `?date=2026-10-27` 都能打开同一页；存档里的标题和“查看这一期”都指向固定地址。
- **分享图**：`/assets/og.png`，1200×630，`og:image` 与 `twitter:image` 都已指向它。换图时保持尺寸不变即可。
- **分类筛选**：点“造 / 用 / 看 / 玩”会把状态写进 `#cat=build` 这类 hash，复制网址即可分享当天的某一类；键盘按 `R` 等于“随手翻一处”。
- **存档过滤**：存档区的输入框只做本地子串匹配，不请求服务器，也不建索引。

> 这些功能都不依赖 JavaScript 才能被收录：RSS、固定地址、`og:image` 和正文链接都是服务端输出。筛选、存档过滤和“随手翻”才需要脚本。

## 运行逻辑

### 每天什么时候切换

`config.php` 里的 `site.drop_hour` 默认是 `9`，时区固定为 `Asia/Shanghai`。

- 09:00 前访问：显示前一天那一期；
- 09:00 后访问：显示当天新一期；
- 如果当天还没有缓存，会先同步构建一次；
- 页面里的 `app.js` 会在加载后请求 `refresh.php?auto=1`，只在缺期或降级时真正抓取；
- 页面跨过 09:00 时会给出“新的一期可能已经就绪”的提示。

### 抓取与兜底

项目来源：

1. Hacker News Show HN
2. GitHub Trending（优先，不依赖 API token）
3. GitHub Search API（备用；未认证时有速率限制）
4. Product Hunt
5. Awwwards
6. `data/seed.php` 里的常青拾遗（来源不足时补位）

新闻来源：

- 国内：中国新闻网时政 / 即时、爱范儿、少数派；
- 国际：中国新闻网国际、联合国新闻中文；CGTN、NPR 只在中文源不足时用英文补位。
- 同一来源默认最多取 2 条（`filters.news_per_source_cap`），超出的先让给下一家；确实凑不齐时才放宽。
- 界面新闻的 RSS 从目标主机连续多次 8 秒超时，`config.php` 里默认 `enabled => false`；本地能取到，但线上不可达，所以方法页不再把它写成现有来源。恢复后改回 `true` 并加回 `$newsCnOrder` 即可。
- 新闻只保留 24 小时内（`filters.news_max_age_hours`）的条目，宁可少几条也不混入旧闻。
- HN 条目要满足“分数 ≥ 10 或评论 ≥ 5”（`filters.hn_min_points` / `hn_min_comments`），不再把刚发出来的低分帖当精选。
- 没抓到项目描述时留白，不再用模板句补齐。

如果某个来源失败：

- 其他来源可以超额补位；
- 仍不足时用常青项目补足 20 个；
- 新闻不足会留空，并在页面上显示“部分栏目暂时未连上”；
- `degraded` 为 `true` 时，前端会在冷却时间后自动重试。

所有标题和描述保留来源原语言，不做翻译或 AI 摘要。新闻只展示标题、来源、时间和原文链接，不复制全文。

### 共享主机的抓取策略

`lib.php` 会先检测 `curl_multi_*` 是否存在：

- 存在：并行抓取，本地通常 6–10 秒；
- 被禁用：自动切到顺序抓取，单请求 6 秒、整次构建 40 秒硬预算，并按 `max_execution_time` 自动收紧；
- 顺序模式下 Awwwards 只解析前两个详情页，失败就退回项目页，避免为“官网地址”拖垮整次构建。

因此同一份代码在普通 Apache 主机和禁用 `curl_multi` 的免费主机上都不会 500。

### 期号与存档

- 第一次成功构建的日期会写进 `cache/meta.json` 的 `epoch`，那天就是第 001 期；
- 之后按自然日递增；
- 默认保留最近 21 天（`cache.keep_days`），更早的 JSON 会在构建时清理；
- 每期有两个固定地址：`/issue/YYYY-MM-DD/`（推荐，`.htaccess` 重写到 `index.php`）和 `?issue=YYYY-MM-DD`；旧的 `?date=YYYY-MM-DD` 继续可用；
- `/issue/` 页面的资源路径都是根绝对路径，从任意深度的固定地址打开都不会丢样式；
- RSS 在 `/feed.xml`，每期一条 item，链接指向固定地址；
- 分类筛选会写进 `#cat=build` 这样的 hash，可以直接分享；再点一次当前分类回到“全部”；
- 存档区有客户端关键词过滤，匹配期号、日期、主题、项目与新闻标题；
- 往期条目只读缓存，不会为旧日期补抓；没有缓存就返回 404。只有“当天这一期”在缺缓存时会同步构建。

**重要**：`cache/meta.json` 是期号锚点。上线后不要删除；迁移主机时把它和 `issue-*.json` 一起备份。删掉它会让下一次构建重新从 001 开始编号。

## 诊断页

`health.php` 不参与页面导航，也不会被搜索引擎收录，适合部署和排障时使用。

| 地址 | 用途 |
| --- | --- |
| `health.php` | JSON 概览 |
| `health.php?format=html` | 给人看的状态页 |
| `health.php?probe=1` | 逐个来源检查连通性，JSON |
| `health.php?probe=1&format=html` | 来源连通性表格 |

`probe=1` 会并行访问全部来源，通常 8–15 秒。它显示 HTTP 状态、抓到的条目数、响应大小和批次耗时；失败行会给出“无响应或超时”一类的提示。

诊断完成后，如果不想公开这一页，可以删除 `health.php`，或在主机面板给它加访问保护。

## 常见问题

### 首页空白 / 500

先看是不是「正在整理今天的二十处」过渡页：这是当天第一次构建，通常 8–15 秒后自动进入。超过半分钟仍停在那里，按顺序检查：

1. PHP 版本是否 ≥ 7.4；
2. `cache/` 是否可写；
3. 是否上传了 `data/cacert.pem`；
4. 主机是否允许 PHP 访问外网（cURL / OpenSSL）；
5. `cache/error.log` 和主机错误日志。

也可以直接打开 `./?fallback=1`，跳过过渡页先看常青拾遗，或者用 `health.php` 确认抓取是否正常。

### 提示“这一期还没有构建成功”

通常是 `cache/` 不可写，或主机连不上来源。先看 `health.php?format=html`，再看 `health.php?probe=1&format=html`。如果只是部分来源失败，页面仍会用其他来源和常青内容补位。

### GitHub 返回 403

GitHub Search API 未认证时有速率限制。程序优先用 GitHub Trending；Trending 不可用时，其他项目和常青内容会补位。偶发的 403 不会让整页空掉。

### cron 返回 403

- URL 里的 `key` 和 `config.php` 不一致；
- 密钥为空时使用了 `force=1`（此时强制刷新被关闭）；
- 主机把请求方法或参数改掉了。先用浏览器直接打开 cron URL 验证。

### 往期 404

那天的缓存不存在，或已经被 21 天保留策略清理。`?date=` 不会补抓旧日期，这是有意的。

### 期号不对

检查 `cache/meta.json` 的 `epoch`。不要为了“从 001 开始”随意删除它，否则已有存档的编号会整体变化。

### 字体加载慢或中文变成宋体/黑体

西文字体已经自托管：`assets/fonts/` 里是 Newsreader 与 DM Sans 的拉丁子集（woff2，OFL 许可），由 `style.css` 的 `@font-face` 直接引用，不再请求 Google Fonts。中文仍然回退到本机的 Noto Serif SC / 思源宋体 / 宋体，或 Noto Sans SC / 苹方 / 微软雅黑。

如果要换字体：

1. 把 woff2 放到 `assets/fonts/`；
2. 改 `assets/style.css` 顶部的 `@font-face` 和 `--serif` / `--sans`；
3. 同步改 `index.php` 与 `loading.php` 里两条 `rel="preload"`（或直接删掉 preload）。

字体文件按 30 天缓存，HTML 里的样式表版本号仍按 `style.css` 的修改时间变化。

中文字体文件很大，建议只做子集，或继续使用系统宋体回退。

### 时区 / 倒计时不对

服务端时区由 `config.php` 的 `site.timezone` 控制，代码固定按 `Asia/Shanghai` 计算 09:00。倒计时在浏览器里按 UTC+8 计算，不依赖访客本地时区。

## 静态托管（GitHub Pages）

PHP 站点继续留在 InfinityFree，同时仓库里有一套静态发布流程：抓取在 GitHub Actions 里跑，页面导出成纯 HTML，由 Pages 直接提供，不再经过 InfinityFree 的 JS 挑战，RSS 阅读器也能直接取。

- 线上地址：<https://voidcode-47.github.io/yubai/>
- 仓库：`voidcode-47/yubai`，工作流在 `.github/workflows/pages.yml`
- 每天 01:05 UTC（北京时间 09:05）自动跑一次，也可以在 Actions 页面手动触发
- 产物：`dist/index.html`（最新一期）、`dist/issue/<日期>/index.html`（每期固定地址）、`dist/feed.xml`、`dist/assets/`
- 每期缓存 `deploy/cache/issue-*.json` 会由工作流提交回仓库，存档和 RSS 才有多期可读
- `base_path` 让同一套模板既能跑在根目录，也能跑在 `/yubai/` 这样的子目录；`site.static=1` 时页面不再调用 `refresh.php`

本地复现静态站：

```powershell
$env:MARGINALIA_STATIC='1'
$env:MARGINALIA_SITE_URL='https://voidcode-47.github.io'
$env:MARGINALIA_BASE_PATH='/yubai'
php bin/build-issue.php
php bin/export-static.php dist
php -S 127.0.0.1:8090 -t .
# 打开 http://127.0.0.1:8090/dist/ 时，记得把 dist 放到 yubai/ 目录下测子路径
```

以后要把 `yubai.66ghz.com` 指向静态站：在 Cloudflare 加一条 CNAME，`yubai` → `voidcode-47.github.io`，代理状态设为 **DNS only**；然后在仓库 Settings → Pages 填自定义域名并勾选 Enforce HTTPS。在此之前，InfinityFree 那套仍然可用，两边内容一致。

## 修改内容

- **站点名称、描述、域名、邮箱、更新时间**：`config.php` 的 `site`；换域名时记得同时改 `site.url` 和 `http.user_agent`。品牌文案里仍有几处固定的“余白”字样，改名时要在 `index.php` 里一并替换；
- **刷新密钥**：`config.php` 的 `refresh.key`；
- **来源开关和地址**：`config.php` 的 `sources`；
- **数量**：`config.php` 的 `counts`（默认 20 / 5 / 5）；如果改动，请同步修改首页和区块文案里的“二十处 / 十条 / 国内五条”等固定说法；
- **常青兜底项目与主题**：`data/seed.php`；
- **分类规则**：`lib.php` 的 `marginalia_category_defs()`（四类的名字与说明）和 `marginalia_category_keywords()`（关键词权重）；
- **视觉**：`assets/style.css`；
- **交互**：`assets/app.js`；
- **首页文案与结构**：`index.php`。

联系邮箱在 `config.php` 的 `site.email`，当前是 `2951065718@qq.com`；页脚“写信”和结尾的联系链接都从这里读取。

## 分类：用 / 造 / 看 / 玩

今日清单按四类分组，每组上方有一条通栏标题，下面是连续的编号卡片；清单顶部另有一排分类按钮，点一下只筛出这一类的项目，再点「全部」恢复。

| 分类 | 收什么 | 主要由哪些来源来 |
| --- | --- | --- |
| 用 · 工具与产品 | 打开就能用的产品、服务、效率工具 | Product Hunt，以及 HN 里像成品的东西 |
| 造 · 开发与基建 | 库、框架、命令行、基础设施 | GitHub Trending / GitHub 仓库链接 |
| 看 · 设计与视觉 | 字体、排版、网站、界面 | Awwwards，以及 HN 里的设计类条目 |
| 玩 · 游戏与实验 | 游戏、玩具、奇想、硬件 | HN 里带 game / toy / ESP 之类的条目 |

规则刻意做得可解释：**来源定大类，只有 Hacker News 和常青拾遗按关键词细分**。Awwwards 直接归「看」，Product Hunt 直接归「用」，GitHub 直接归「造」，不参与打分。这样分类结果不会每天随权重漂移。

关键词打分在 `marginalia_category_keywords()` 里，标题和描述各算一次，命中就加权重；一个都没命中时按来源兜底（GitHub → 造，Awwwards → 看，其余 → 用）。分类结果会写进当天缓存 JSON 的 `category` 字段；老缓存没有这个字段时，`index.php` 会用同一套函数现场补算，所以往期也能按四类显示。

分类是近似的，页面上也照实写了「偶尔会放错抽屉」。改关键词时，可以用 `health.php` 之外的方式快速回归：

```powershell
php -r "require 'lib.php'; foreach (json_decode(file_get_contents('cache/issue-2026-10-27.json'), true)['projects'] as $p) { echo marginalia_project_category($p), ' ', $p['title'], PHP_EOL; }"
```

分组之后，某一类最后一行不满时会留白，不再用底色填满——这是有意的，留白本来就是这一页的名字。分类按钮里每格的数字是该类的条数，四类相加等于当天的项目总数。

筛选只在浏览器里切换卡片的 `hidden`，不写入 URL，也不影响新闻区：新闻是独立信息流，页面上已经写明「不受上方标签筛选控制」。再点一次当前分类会回到「全部」。这样刷新或分享链接时不会带上一半的筛选状态，也不会和 `?date=`、锚点滚动打架。随机漫游会优先从当前筛选后仍可见的项目里抽。

## 锚点、往期导航与冷启动

页面是服务端渲染，没有前端 hash 路由。`?date=YYYY-MM-DD` 仍然是往期的唯一地址，保证直接访问、分享和搜索引擎都能拿到完整 HTML。页内锚点约定：

| 锚点 | 落在哪里 |
| --- | --- |
| `#home` | 首屏 hero |
| `#issue-001` | 当前这一期的开头（零高度别名，期号随日期变化） |
| `#today` / `#news` / `#method` / `#archive` | 对应区块 |
| `#top` | 页面顶部别名，页脚「回到顶部」使用 |

从往期页点导航时，链接会带上当前期号，例如 `?date=2026-10-26#news`，不会掉回最新一期。存档列表的标题就是该期的链接（`?date=…` + `#today`）；正在翻阅的那一期不再生成自刷新链接，而是显示「正在翻阅」标记。

当天缓存不存在时（包括直接访问 `?date=今天`），`index.php` 不再让访客对着白屏等抓取，而是先输出 `loading.php`：页面自己请求 `refresh.php?auto=1`，构建完成后自动进入首页；连续失败三次会给出「先看常青拾遗」（`?fallback=1`，跳过过渡页直接拿兜底内容）和「翻上一期」。如果当天刚抓取失败、还在 `cache.fail_cooldown` 冷却里，则直接渲染兜底页，不再显示过渡页。

README 里的「二十处 / 十条 / 国内五条」等固定文案与 `config.php` 的 `counts` 是两套东西，改数量时两处都要动。


## 域名与迁移

主域名是 `research-agent.my-place.us`，2026-10-27 新增了子域 `yubai.66ghz.com` 并把站点迁过去：

- 免费套餐不能在主域下再加子域（`my-place.us` 不在子域后缀列表里），也不能把主域子域挂成别名；
- 可选后缀里只有 `66ghz.com`、`iblogger.org` 带 `*.` 通配符证书，最终选了更短的 `66ghz.com`；
- 新子域的文档根是 `yubai.66ghz.com/htdocs`，与旧的 `htdocs` 是同一个账户、同一块磁盘；
- 旧文档根的 `.htaccess` 顶部加了 301，路径和查询串原样带到新域名；
- 迁移时把 `cache/meta.json` 和 `issue-2026-10-27.json` 一起复制过去，所以期号没有重新从 001 开始。

**DNS 注意**：新建子域时，权威 DNS 先给过一个占位地址（`185.27.134.24`），随后改成实际服务器 `185.27.134.176`，TTL 是 6 小时。公共 DNS（8.8.8.8、9.9.9.9、223.5.5.5）很快就能拿到新值，但个别 ISP 的缓存可能还停在旧地址，表现为“连不上”。等 TTL 过期即恢复，不需要改配置。

## 已知限制

- 这是共享主机上的文件缓存方案，不是数据库；并发很高时依赖 `flock` 和原子写入，适合个人日刊，不适合大规模并发。
- 免费主机的出网访问、PHP 执行时间、cron 稳定性都不保证；来源全部失败时只能显示常青拾遗。
- 目标主机禁用了 `curl_multi_*`，只能顺序抓取：构建约 8–15 秒，比并行慢，但不会超时。
- InfinityFree 的 JS 反爬挑战依赖固定的 `__test` cookie；外部 cron 需要带 cookie 和浏览器 UA，挑战常量若更换需要手动更新一次。
- 同一挑战在**首次访问**时会把地址改写成 `?i=1` 并丢掉 `#hash`，所以第一次直接打开分享的 `#archive` / `#news` 链接可能停在首页顶部；拿到 cookie 后再点页内导航就正常。这是主机层行为，PHP 无法保留被浏览器丢掉的片段。
- 内容是全自动汇总，没有人工逐条审阅；关键词过滤只能挡掉一部分低质或不适内容。
- 新闻版权归原来源所有；本站只保存标题、来源、时间和链接。
- 没有订阅、评论、统计和后台编辑器。改内容需要编辑文件并重新上传。
