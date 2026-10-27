<?php
/**
 * 余白 MARGINALIA — 首页与往期视图
 *
 * 页面本身不抓取。内容来自 cache/issue-YYYY-MM-DD.json：
 *  - 没有当天缓存时，先同步构建一次，让第一位访客也能看到完整一期；
 *  - 之后由页面里的 app.js 在后台访问 refresh.php?auto=1，只有当期缺内容时才重抓。
 */

require_once __DIR__ . '/lib.php';

@set_time_limit(50);

$requested = '';
if (isset($_GET['date'])) {
    $requested = trim((string) $_GET['date']);
} elseif (isset($_GET['issue'])) {
    // ?issue=2026-10-27 与 /issue/2026-10-27/ 等价，旧链接也能一直用。
    $requested = trim((string) $_GET['issue']);
}
$currentIssueDate = marginalia_current_issue_date();

$styleFile = __DIR__ . '/assets/style.css';
$styleVersion = is_file($styleFile) ? (string) filemtime($styleFile) : '1';
$scriptFile = __DIR__ . '/assets/app.js';
$scriptVersion = is_file($scriptFile) ? (string) filemtime($scriptFile) : '1';

// 冷启动：当天还没有缓存、也没有处在失败冷却里时，先给过渡页。
// 否则访客要对着白屏等 8–15 秒，再由 PHP 一次性吐出整页。
// 直接访问 `?date=今天` 也算冷启动；构建完成后统一回首页，避免日期参数和缓存状态分叉。
$coldStart = ($requested === '' || $requested === $currentIssueDate)
    && !isset($_GET['fallback'])
    && !marginalia_load_issue($currentIssueDate)
    && !marginalia_build_cooldown_active($currentIssueDate);

if ($coldStart) {
    $loadingSiteName = (string) marginalia_config('site.name');
    $loadingSiteNameEn = (string) marginalia_config('site.name_en');
    $loadingIssuePadded = marginalia_issue_padded($currentIssueDate);
    $loadingDropHour = (int) marginalia_config('site.drop_hour');
    $loadingDropLabel = str_pad((string) $loadingDropHour, 2, '0', STR_PAD_LEFT) . ':00';
    $loadingLatestDate = marginalia_latest_issue_date();
    $loadingStyleVersion = $styleVersion;
    define('MARGINALIA_LOADING', true);
    require __DIR__ . '/loading.php';
    exit;
}

$issueDate = $currentIssueDate;
$issue = null;
$notFound = false;
$isArchiveView = false;

if ($requested !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) {
        $notFound = true;
        http_response_code(404);
    } else {
        $issueDate = $requested;
        if ($requested === $currentIssueDate) {
            $issue = marginalia_ensure_issue($currentIssueDate);
        } else {
            // 往期只读缓存，不允许任意日期触发抓取。
            $issue = marginalia_load_issue($requested);
        }
        if (!$issue) {
            $notFound = true;
            http_response_code(404);
        }
    }
} else {
    $issue = marginalia_ensure_issue($currentIssueDate);
}

if (!$issue && !$notFound) {
    // 抓取失败时至少让页面能打开：常青拾遗 + 空新闻，随后由前端重试。
    $issue = marginalia_make_fallback_issue($issueDate);
    if (!$issue) {
        $notFound = true;
        http_response_code(404);
    }
}

$isArchiveView = !$notFound && $issueDate !== $currentIssueDate;

$latestDate = marginalia_latest_issue_date();
if (!$latestDate) {
    $latestDate = $issueDate;
}
$latestIssue = $latestDate === $issueDate && $issue ? $issue : marginalia_load_issue($latestDate);

$issueNumber = $issue ? (int) ($issue['issue'] ?? marginalia_issue_number($issueDate)) : marginalia_issue_number($issueDate);
$issuePadded = str_pad((string) max(1, $issueNumber), 3, '0', STR_PAD_LEFT);
$weekday = marginalia_weekday($issueDate);
$dateLabel = marginalia_format_date($issueDate);
$theme = $issue ? (string) ($issue['theme'] ?? marginalia_theme($issueDate)) : marginalia_theme($issueDate);
$counts = $issue && isset($issue['counts']) && is_array($issue['counts'])
    ? $issue['counts']
    : ['projects' => 0, 'news_cn' => 0, 'news_intl' => 0];
$projectCount = (int) ($counts['projects'] ?? 0);
$newsCnCount = (int) ($counts['news_cn'] ?? 0);
$newsIntlCount = (int) ($counts['news_intl'] ?? 0);
$newsTotal = $newsCnCount + $newsIntlCount;
$degraded = !empty($issue['degraded']);

$projects = $issue && isset($issue['projects']) && is_array($issue['projects']) ? $issue['projects'] : [];
$projects = array_values(array_filter($projects, function ($project) {
    return is_array($project) && !empty($project['title']) && !empty($project['url']);
}));
$lead = $projects ? $projects[0] : null;
$leadLetter = '';
if ($lead) {
    $leadLetter = function_exists('mb_substr')
        ? mb_substr((string) $lead['title'], 0, 1, 'UTF-8')
        : substr((string) $lead['title'], 0, 1);
    if (preg_match('/^[a-z]$/', $leadLetter)) {
        $leadLetter = strtoupper($leadLetter);
    }
}

$newsCn = $issue && isset($issue['news_cn']) && is_array($issue['news_cn']) ? array_values($issue['news_cn']) : [];
$newsIntl = $issue && isset($issue['news_intl']) && is_array($issue['news_intl']) ? array_values($issue['news_intl']) : [];

$generatedLabel = '';
if ($issue && !empty($issue['generated_at'])) {
    $generatedTs = strtotime((string) $issue['generated_at']);
    if ($generatedTs) {
        $generatedLabel = date('Y.m.d H:i', $generatedTs);
    }
}

$sourceLabels = [];
foreach ($projects as $project) {
    $label = trim((string) ($project['source'] ?? ''));
    if ($label !== '') {
        $sourceLabels[$label] = $label;
    }
}
$sourceLabels = array_values($sourceLabels);
$sourceSummary = $sourceLabels ? implode(' / ', array_slice($sourceLabels, 0, 6)) : '公开来源';

// 今日清单按「用 / 造 / 看 / 玩」分组。老缓存里没有 category 字段时现场补算，
// 保证往期也能用同一套结构显示。
$categoryDefs = marginalia_category_defs();
$projectGroups = [];
$leadCat = '';
foreach ($projects as $projectIndex => $project) {
    $projectCat = (string) ($project['category'] ?? '');
    if (!isset($categoryDefs[$projectCat])) {
        $projectCat = marginalia_project_category($project);
    }
    if ($projectIndex === 0) {
        $leadCat = $projectCat;
    }
    $projectGroups[$projectCat][] = $project;
}
$groupedCats = [];
foreach (array_keys($categoryDefs) as $catKey) {
    if (!empty($projectGroups[$catKey])) {
        $groupedCats[] = $catKey;
    }
}
// 本期首选所在的抽屉排在最前，让那张跨列的大卡片落在清单开头。
if ($leadCat !== '' && in_array($leadCat, $groupedCats, true)) {
    $groupedCats = array_values(array_diff($groupedCats, [$leadCat]));
    array_unshift($groupedCats, $leadCat);
}
$leadUrl = $projects ? (string) ($projects[0]['url'] ?? '') : '';

$siteName = (string) marginalia_config('site.name');
$siteNameEn = (string) marginalia_config('site.name_en');
$siteTagline = (string) marginalia_config('site.tagline');
$email = (string) marginalia_config('site.email');
$dropHour = (int) marginalia_config('site.drop_hour');
$dropLabel = str_pad((string) $dropHour, 2, '0', STR_PAD_LEFT) . ':00';

$pageTitle = $notFound
    ? '没有找到这一期｜' . $siteName . ' ' . $siteNameEn
    : ($isArchiveView
        ? '第 ' . $issuePadded . ' 期 · ' . $dateLabel . '｜' . $siteName . ' ' . $siteNameEn
        : $siteName . ' ' . $siteNameEn . '｜给好奇心，留一点余白');
$pageDescription = $notFound
    ? '这一期还没有落进余白的存档。'
    : ($isArchiveView
        ? '余白第 ' . $issuePadded . ' 期，' . $dateLabel . '：' . $projectCount . ' 个网站与项目，'
            . $newsCnCount . ' 条国内消息，' . $newsIntlCount . ' 条国际消息。'
        : (string) marginalia_config('site.description'));
$canonical = $notFound
    ? marginalia_abs('/')
    : ($isArchiveView ? marginalia_abs('/issue/' . rawurlencode($issueDate) . '/') : marginalia_abs('/'));
$feedUrl = marginalia_abs('/feed.xml');
$ogImage = marginalia_abs('/assets/og.png');
// 静态托管没有 refresh.php，导出时把后台刷新关掉。
$refreshUrl = marginalia_config('site.static') ? '' : marginalia_link('/refresh.php?auto=1');

$nextDrop = marginalia_next_drop_ts();
$nextDropLabel = date('m.d', $nextDrop) . ' ' . marginalia_weekday(date('Y-m-d', $nextDrop)) . ' / ' . $dropLabel;

$archiveDates = marginalia_archive_dates(0);
$olderDates = array_values(array_filter($archiveDates, function ($date) use ($latestDate) {
    return $date !== $latestDate;
}));

// 导航带期号：在往期页点「新闻 / 方法 / 存档」时留在这一期，不再掉回最新一期。
// 本期用站点根，避免首页出现 `?date=` 这样的冗余地址。
$navPrefix = $isArchiveView ? marginalia_issue_url($issueDate) : marginalia_link('/');
$issueAnchor = 'issue-' . $issuePadded;

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: ' . ($notFound ? 'no-store, no-cache, must-revalidate, max-age=0' : 'public, max-age=60'));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= marginalia_esc($pageTitle) ?></title>
  <meta name="description" content="<?= marginalia_esc($pageDescription) ?>">
  <meta name="theme-color" content="#f5f4ed">
  <link rel="canonical" href="<?= marginalia_esc($canonical) ?>">
  <link rel="alternate" type="application/rss+xml" title="<?= marginalia_esc($siteName . ' ' . $siteNameEn) ?>" href="<?= marginalia_esc($feedUrl) ?>">
  <meta property="og:title" content="<?= marginalia_esc($pageTitle) ?>">
  <meta property="og:description" content="<?= marginalia_esc($pageDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= marginalia_esc($siteName . ' ' . $siteNameEn) ?>">
  <meta property="og:locale" content="zh_CN">
  <meta property="og:url" content="<?= marginalia_esc($canonical) ?>">
  <meta property="og:image" content="<?= marginalia_esc($ogImage) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="余白 MARGINALIA，一份关于互联网角落的日刊">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= marginalia_esc($pageTitle) ?>">
  <meta name="twitter:description" content="<?= marginalia_esc($pageDescription) ?>">
  <meta name="twitter:image" content="<?= marginalia_esc($ogImage) ?>">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23f5f4ed'/%3E%3Ccircle cx='32' cy='32' r='18' fill='none' stroke='%231b365d' stroke-width='1.5'/%3E%3Cpath d='M22 34h20' stroke='%231b365d' stroke-width='1.5'/%3E%3Ccircle cx='32' cy='24' r='1.8' fill='%231b365d'/%3E%3C/svg%3E">
  <link rel="preload" href="<?= marginalia_esc(marginalia_link('/assets/fonts/newsreader-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= marginalia_esc(marginalia_link('/assets/fonts/dmsans-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= marginalia_esc(marginalia_link('/assets/style.css')) ?>?v=<?= marginalia_esc($styleVersion) ?>">
  <script src="<?= marginalia_esc(marginalia_link('/assets/app.js')) ?>?v=<?= marginalia_esc($scriptVersion) ?>" defer></script>
</head>
<body<?= $notFound ? ' class="is-notfound"' : '' ?>
  data-issue-date="<?= marginalia_esc($issueDate) ?>"
  data-current-date="<?= marginalia_esc($currentIssueDate) ?>"
  data-latest-date="<?= marginalia_esc((string) $latestDate) ?>"
  data-issue-number="<?= marginalia_esc($issuePadded) ?>"
  data-degraded="<?= $degraded ? '1' : '0' ?>"
  data-is-latest="<?= !$isArchiveView && !$notFound ? '1' : '0' ?>"
  data-next-drop="<?= (int) $nextDrop ?>"
  data-drop-hour="<?= (int) $dropHour ?>"
  data-refresh-url="<?= marginalia_esc($refreshUrl) ?>">
  <a class="skip-link" href="#main">跳到主要内容</a>
  <div class="grain" aria-hidden="true"></div>

  <header class="nav">
    <div class="nav-inner">
      <a class="brand" href="<?= marginalia_esc(marginalia_link('/#home')) ?>" aria-label="<?= marginalia_esc($siteName) ?>首页">
        <span class="brand-cn"><?= marginalia_esc($siteName) ?></span>
        <span class="brand-en" aria-hidden="true"><?= marginalia_esc($siteNameEn) ?></span>
      </a>
      <nav class="nav-links" aria-label="主导航">
        <a href="<?= marginalia_esc($navPrefix) ?>#today">本期</a>
        <a href="<?= marginalia_esc($navPrefix) ?>#news">新闻</a>
        <a href="<?= marginalia_esc($navPrefix) ?>#method">方法</a>
        <a href="<?= marginalia_esc($navPrefix) ?>#archive">存档</a>
      </nav>
      <a class="nav-issue" href="<?= marginalia_esc($navPrefix) ?>#today" aria-label="当前期数：第 <?= marginalia_esc($issuePadded) ?> 期，回到本期清单">
        <span class="dot" aria-hidden="true"></span>
        <span>第 <?= marginalia_esc($issuePadded) ?> 期</span>
      </a>
    </div>
    <span class="nav-progress" aria-hidden="true"></span>
  </header>

<?php if ($isArchiveView): ?>
  <div class="viewing-bar">
    <div class="shell viewing-inner">
      <span class="viewing-tag">往期</span>
      <span>正在翻阅 <?= marginalia_esc($dateLabel) ?> 的第 <?= marginalia_esc($issuePadded) ?> 期。</span>
      <a href="<?= marginalia_esc(marginalia_link('/')) ?>">回到最新一期 →</a>
    </div>
  </div>
<?php endif; ?>

  <main id="main">
<?php if (!$notFound): ?>
    <span class="anchor-alias" id="<?= marginalia_esc($issueAnchor) ?>" aria-hidden="true"></span>
<?php endif; ?>
<?php if ($notFound): ?>
    <section class="section notfound">
      <div class="shell">
        <div class="section-folio">
          <span class="no">404</span>
          <span class="rule" aria-hidden="true"></span>
          <span>没有这一页</span>
        </div>
        <h1>这一期，<br>还没有落进存档。</h1>
        <p>可能是日期写错了，也可能这天的抓取还没有完成。余白每天只保留最近一段时间的往期，更早的页面会慢慢退场。</p>
        <a class="btn-primary" href="<?= marginalia_esc(marginalia_link('/')) ?>">
          回到最新一期
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
            <path d="M8 2.5v11M3.5 9 8 13.5 12.5 9"/>
          </svg>
        </a>
      </div>
    </section>
<?php else: ?>
    <span class="anchor-alias" id="top" aria-hidden="true"></span>
    <section class="hero" id="home" aria-labelledby="hero-title">
      <div class="shell hero-inner">
        <div class="hero-copy">
          <p class="hero-kicker"><?= marginalia_esc($siteTagline) ?></p>
          <h1 id="hero-title">
            <span class="line"><span class="line-inner">给好奇心，</span></span>
            <span class="line line--indent"><span class="line-inner">留一点余白。</span></span>
          </h1>
          <p class="hero-lede">每天二十处自动搜集的网站与项目，再加国内外十条消息。不用注册，不追着你，也不要求你全部读完。</p>
          <div class="hero-actions">
            <a class="btn-primary" href="#today">
              翻开本期
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                <path d="M8 2.5v11M3.5 9 8 13.5 12.5 9"/>
              </svg>
            </a>
            <button class="btn-text" type="button" data-random-pick>
              随手翻一处
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                <path d="M8 1.8c.5 4.4 1.8 6.2 6.2 6.2-4.4 0-5.7 1.8-6.2 6.2C7.5 9.8 6.2 8 1.8 8 6.2 8 7.5 6.2 8 1.8Z"/>
              </svg>
            </button>
          </div>
          <p class="hero-note">每日 <?= marginalia_esc($dropLabel) ?> 自动更新。没有推荐流，也没有下一页。</p>
        </div>

        <div class="hero-visual">
          <div class="stamp" role="group" aria-label="下一期更新倒计时">
            <svg class="stamp-ring" viewBox="0 0 320 320" aria-hidden="true">
              <defs>
                <path id="stampPath" d="M160,160 m-128,0 a128,128 0 1,1 256,0 a128,128 0 1,1 -256,0"/>
              </defs>
              <text>
                <textPath href="#stampPath" textLength="800" lengthAdjust="spacing">A LITTLE ROOM FOR CURIOSITY · ISSUE <?= marginalia_esc($issuePadded) ?> · SLOW WEB · </textPath>
              </text>
            </svg>
            <div class="stamp-center">
              <span class="stamp-label">下一期</span>
              <span class="stamp-time" id="next-clock">--:--:--</span>
              <span class="stamp-sub">每日 <?= marginalia_esc($dropLabel) ?> / UTC+8</span>
            </div>
          </div>
        </div>
      </div>

      <div class="shell">
        <div class="issue-bar" aria-label="本期信息">
          <div class="issue-item">
            <span class="issue-label">期号</span>
            <span class="issue-value">第 <?= marginalia_esc($issuePadded) ?> 期</span>
          </div>
          <div class="issue-item">
            <span class="issue-label">日期</span>
            <span class="issue-value"><?= marginalia_esc($dateLabel) ?> <?= marginalia_esc($weekday) ?></span>
          </div>
          <div class="issue-item">
            <span class="issue-label">主题</span>
            <span class="issue-value"><?= marginalia_esc($theme) ?></span>
          </div>
          <div class="issue-item">
            <span class="issue-label">收录</span>
            <span class="issue-value"><?= (int) $projectCount ?> 处 + <?= (int) $newsTotal ?> 条</span>
          </div>
        </div>
      </div>
    </section>

    <section class="section today" id="today" aria-labelledby="today-title">
      <div class="shell">
        <div class="section-head">
          <div>
            <div class="section-folio">
              <span class="no">01</span>
              <span class="rule" aria-hidden="true"></span>
              <span>今日清单</span>
            </div>
            <h2 id="today-title">今日二十处</h2>
          </div>
          <p class="section-note">从公开来源自动汇集的网站与项目，按「用 / 造 / 看 / 玩」分成四类。分类由来源与标题关键词推出来，偶尔也会放错抽屉；标题和描述保留原文，点开就是原站。</p>
        </div>

        <div class="notice<?= $degraded ? ' notice--warn' : '' ?>">
          <span class="notice-dot" aria-hidden="true"></span>
          <span>
            <strong>自动汇总</strong>
            <?php if ($generatedLabel !== ''): ?> · 抓取于 <?= marginalia_esc($generatedLabel) ?><?php endif; ?>
            · 来源：<?= marginalia_esc($sourceSummary) ?>
            <?php if ($degraded): ?> · 部分栏目暂时未连上，缺项稍后会自动重试。<?php endif; ?>
          </span>
        </div>

        <?php if ($projects): ?>
          <nav class="cat-index" aria-label="按分类筛选项目">
            <button class="cat-chip" type="button" data-filter="all" aria-controls="picks" aria-pressed="true">
              <span class="cat-chip-hanzi" aria-hidden="true">全</span>
              <span class="cat-chip-name">全部</span>
              <span class="cat-chip-count"><?= count($projects) ?></span>
            </button>
            <?php foreach ($groupedCats as $catKey): ?>
              <button class="cat-chip" type="button" data-filter="<?= marginalia_esc($catKey) ?>" aria-controls="picks" aria-pressed="false">
                <span class="cat-chip-hanzi" aria-hidden="true"><?= marginalia_esc($categoryDefs[$catKey]['hanzi']) ?></span>
                <span class="cat-chip-name"><?= marginalia_esc($categoryDefs[$catKey]['name']) ?></span>
                <span class="cat-chip-count"><?= count($projectGroups[$catKey]) ?></span>
              </button>
            <?php endforeach; ?>
          </nav>

          <div class="picks" id="picks">
            <?php $displayNo = 0; ?>
            <?php foreach ($groupedCats as $catKey): ?>
              <?php $catMeta = $categoryDefs[$catKey]; ?>
              <div class="pick-cat" id="cat-<?= marginalia_esc($catKey) ?>" data-cat="<?= marginalia_esc($catKey) ?>">
                <span class="pick-cat-hanzi" aria-hidden="true"><?= marginalia_esc($catMeta['hanzi']) ?></span>
                <span class="pick-cat-name"><?= marginalia_esc($catMeta['name']) ?></span>
                <span class="pick-cat-hint"><?= marginalia_esc($catMeta['hint']) ?></span>
                <span class="pick-cat-count"><?= count($projectGroups[$catKey]) ?> 处</span>
              </div>
              <?php foreach ($projectGroups[$catKey] as $project): ?>
                <?php
                $displayNo++;
                $isLead = $leadUrl !== '' && (string) ($project['url'] ?? '') === $leadUrl;
                $projectUrl = (string) ($project['url'] ?? '');
                $projectTitle = (string) ($project['title'] ?? '');
                $projectDesc = (string) ($project['description'] ?? '');
                $projectDescLang = (string) ($project['description_lang'] ?? '');
                $projectSource = (string) ($project['source'] ?? '');
                $projectMeta = (string) ($project['meta'] ?? '');
                $projectDomain = (string) ($project['domain'] ?? '');
                $projectTags = isset($project['tags']) && is_array($project['tags']) ? $project['tags'] : [];
                $projectDiscuss = trim((string) ($project['discuss_url'] ?? ''));
                $projectNo = str_pad((string) $displayNo, 2, '0', STR_PAD_LEFT);
                ?>
                <article class="pick<?= $isLead ? ' pick--lead' : '' ?>" data-cat="<?= marginalia_esc($catKey) ?>" data-href="<?= marginalia_esc($projectUrl) ?>">
                  <div class="pick-main">
                    <div class="pick-head">
                      <span class="pick-no"><?= $projectNo ?></span>
                      <span class="pick-source"><?= marginalia_esc($projectSource) ?></span>
                      <svg class="pick-glyph" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.1" aria-hidden="true">
                        <?= (string) ($project['glyph'] ?? marginalia_glyph($displayNo)) ?>
                      </svg>
                    </div>
                    <h3><a class="pick-link" href="<?= marginalia_esc($projectUrl) ?>" target="_blank" rel="noopener noreferrer" data-random data-title="<?= marginalia_esc($projectTitle) ?>"><?= marginalia_esc($projectTitle) ?></a></h3>
                    <?php if ($projectDesc !== ''): ?>
                      <p class="pick-desc"<?= $projectDescLang === 'en' ? ' lang="en"' : '' ?>><?= marginalia_esc($projectDesc) ?></p>
                    <?php endif; ?>
                    <?php if ($projectTags): ?>
                      <ul class="pick-tags" aria-label="标签">
                        <?php foreach (array_slice($projectTags, 0, 3) as $tag): ?>
                          <li><?= marginalia_esc($tag) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                    <div class="pick-foot">
                      <?php if ($projectDiscuss !== ''): ?>
                        <a class="pick-discuss" href="<?= marginalia_esc($projectDiscuss) ?>" target="_blank" rel="noopener noreferrer" title="到 Hacker News 看这一条的讨论"><?= marginalia_esc($projectMeta) ?> · 讨论 ↗</a>
                      <?php else: ?>
                        <span class="pick-meta"><?= marginalia_esc($projectMeta) ?></span>
                      <?php endif; ?>
                      <span class="pick-domain"><?= marginalia_esc($projectDomain !== '' ? $projectDomain : '原站') ?>
                        <svg class="pick-arrow" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                          <path d="M4 12 12 4M6 4h6v6"/>
                        </svg>
                      </span>
                    </div>
                  </div>
                  <?php if ($isLead): ?>
                    <span class="pick-cover" aria-hidden="true">
                      <span class="pc-head">
                        <span>本期首选</span>
                        <span>No. <?= $projectNo ?></span>
                      </span>
                      <span class="pc-letter"><?= marginalia_esc($leadLetter) ?><small><?= marginalia_esc($projectSource) ?></small></span>
                      <span class="pc-foot">
                        <span><?= marginalia_esc($projectDomain !== '' ? $projectDomain : '原站') ?></span>
                        <span class="pc-mark">余</span>
                      </span>
                    </span>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="archive-empty">今天的项目还没有连上。页面会在后台自动重试，稍后回来看看。</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="section news" id="news" aria-labelledby="news-title">
      <div class="shell">
        <div class="section-head">
          <div>
            <div class="section-folio">
              <span class="no">02</span>
              <span class="rule" aria-hidden="true"></span>
              <span>今日消息</span>
            </div>
            <h2 id="news-title">十条消息</h2>
          </div>
          <p class="section-note">国内五条，国际五条。只保留标题、来源、时间与原链，全文请去原站读。<span class="filter-note">独立信息流，不受上方标签筛选控制。</span></p>
        </div>

        <div class="news-grid">
          <div class="news-col">
            <div class="news-head">
              <h3>国内</h3>
              <span class="news-count"><?= str_pad((string) $newsCnCount, 2, '0', STR_PAD_LEFT) ?> 条</span>
            </div>
            <div class="news-list">
              <?php if ($newsCn): ?>
                <?php foreach ($newsCn as $index => $item): ?>
                  <?php $itemLang = (string) ($item['lang'] ?? 'zh'); ?>
                  <a class="news-item" href="<?= marginalia_esc($item['url'] ?? '') ?>" target="_blank" rel="noopener noreferrer">
                    <span class="news-no"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="news-body">
                      <span class="news-title"<?= $itemLang === 'en' ? ' lang="en"' : '' ?>><?= marginalia_esc($item['title'] ?? '') ?></span>
                      <span class="news-meta">
                        <span class="news-src"><?= marginalia_esc($item['source'] ?? '') ?></span>
                        <span class="news-time"><?= marginalia_esc($item['time_label'] ?? '') ?></span>
                        <?php if ($itemLang !== 'zh'): ?><span class="news-lang">英文</span><?php endif; ?>
                      </span>
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="news-empty">这一栏的消息还没连上，稍后会自动重试。</p>
              <?php endif; ?>
            </div>
          </div>

          <div class="news-col">
            <div class="news-head">
              <h3>国际</h3>
              <span class="news-count"><?= str_pad((string) $newsIntlCount, 2, '0', STR_PAD_LEFT) ?> 条</span>
            </div>
            <div class="news-list">
              <?php if ($newsIntl): ?>
                <?php foreach ($newsIntl as $index => $item): ?>
                  <?php $itemLang = (string) ($item['lang'] ?? 'zh'); ?>
                  <a class="news-item" href="<?= marginalia_esc($item['url'] ?? '') ?>" target="_blank" rel="noopener noreferrer">
                    <span class="news-no"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="news-body">
                      <span class="news-title"<?= $itemLang === 'en' ? ' lang="en"' : '' ?>><?= marginalia_esc($item['title'] ?? '') ?></span>
                      <span class="news-meta">
                        <span class="news-src"><?= marginalia_esc($item['source'] ?? '') ?></span>
                        <span class="news-time"><?= marginalia_esc($item['time_label'] ?? '') ?></span>
                        <?php if ($itemLang !== 'zh'): ?><span class="news-lang">英文</span><?php endif; ?>
                      </span>
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="news-empty">这一栏的消息还没连上，稍后会自动重试。</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="method" id="method" aria-labelledby="method-title">
      <div class="shell">
        <div class="section-head">
          <div>
            <div class="section-folio">
              <span class="no">03</span>
              <span class="rule" aria-hidden="true"></span>
              <span>内容来源</span>
            </div>
            <h2 id="method-title">这些内容怎么来</h2>
          </div>
          <p class="section-note">没有推荐系统，也没有赞助位。程序每天汇总一次，页面只负责把它排得安静一点。</p>
        </div>

        <div class="method-grid">
          <div>
            <p class="method-statement">把一点好奇，<br>每天收进一页。</p>
            <p class="method-body">项目来自 Hacker News 的 Show HN、GitHub Trending、Product Hunt、Awwwards，以及一份长期可读的常青拾遗。新闻来自中国新闻网、爱范儿、钛媒体、联合国新闻等公开订阅源。某个来源失效时，页面会用其他来源或常青内容补位，并在上方标注。每期有固定地址，也可以从 <a href="<?= marginalia_esc(marginalia_link('/feed.xml')) ?>">/feed.xml</a> 订阅。</p>
          </div>
          <div class="principles">
            <div class="principle">
              <h3>自动汇总</h3>
              <p>每天 <?= marginalia_esc($dropLabel) ?> 前后抓取一次。没有人工逐条审阅，也不保证每一处都合你口味。</p>
            </div>
            <div class="principle">
              <h3>保留原文</h3>
              <p>标题与描述保留来源原语言，只做必要的截断和排版。新闻只展示标题、来源、时间与原链，不复制全文。</p>
            </div>
            <div class="principle">
              <h3>分成四类</h3>
              <p>用、造、看、玩，是按来源和标题关键词推出来的，不是人工归档。所以偶尔会有东西放错抽屉——当它是这一页的脾气。</p>
            </div>
            <div class="principle">
              <h3>允许离开</h3>
              <p>二十处加十条，是看得完的分量。没有无限滚动，读完这一页，就应该回到生活里去。</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section archive" id="archive" aria-labelledby="archive-title">
      <div class="shell">
        <div class="section-head">
          <div>
            <div class="section-folio">
              <span class="no">04</span>
              <span class="rule" aria-hidden="true"></span>
              <span>往期存档</span>
            </div>
            <h2 id="archive-title">往期的余白</h2>
          </div>
          <p class="section-note">每天一期，自动落进这个书架。打开一期，可以看当天的二十处与十条消息。</p>
        </div>

        <div class="archive-search">
          <label for="archive-filter">在往期里找</label>
          <input id="archive-filter" type="search" placeholder="关键词，例如 字体、Hacker News、联合国" autocomplete="off" spellcheck="false" aria-describedby="archive-filter-count">
          <span class="archive-search-count" id="archive-filter-count" aria-live="polite"></span>
        </div>
        <p class="archive-empty" id="archive-filter-empty" hidden>没有找到匹配的往期。换个词试试，或清空输入。</p>

        <div class="archive-list">
          <?php
          $latestNumber = $latestDate ? marginalia_issue_padded($latestDate) : $issuePadded;
          $latestTheme = $latestIssue ? (string) ($latestIssue['theme'] ?? marginalia_theme($latestDate)) : $theme;
          $latestCounts = $latestIssue && isset($latestIssue['counts']) && is_array($latestIssue['counts'])
              ? $latestIssue['counts']
              : ['projects' => 0, 'news_cn' => 0, 'news_intl' => 0];
          $latestTotalNews = (int) ($latestCounts['news_cn'] ?? 0) + (int) ($latestCounts['news_intl'] ?? 0);
          ?>
          <a class="archive-current" href="<?= marginalia_esc(marginalia_link('/#today')) ?>" aria-label="回到第 <?= marginalia_esc($latestNumber) ?> 期，<?= marginalia_esc($latestTheme) ?>">
            <span class="row-no"><?= marginalia_esc($latestNumber) ?></span>
            <span class="row-date"><?= marginalia_esc(marginalia_format_date((string) $latestDate)) ?></span>
            <span class="row-title"><?= marginalia_esc($latestTheme) ?></span>
            <span class="row-meta"><?= $isArchiveView ? '最新一期' : '本期' ?> / <?= (int) ($latestCounts['projects'] ?? 0) ?> 处 · <?= $latestTotalNews ?> 条</span>
            <span class="row-icon row-icon--arrow" aria-hidden="true">↑</span>
          </a>

          <?php if ($olderDates): ?>
            <?php foreach ($olderDates as $archiveDate): ?>
              <?php
              $rowIssue = marginalia_load_issue($archiveDate);
              if (!$rowIssue) {
                  continue;
              }
              $rowCounts = isset($rowIssue['counts']) && is_array($rowIssue['counts'])
                  ? $rowIssue['counts']
                  : ['projects' => 0, 'news_cn' => 0, 'news_intl' => 0];
              $rowNewsTotal = (int) ($rowCounts['news_cn'] ?? 0) + (int) ($rowCounts['news_intl'] ?? 0);
              $rowProjects = isset($rowIssue['projects']) && is_array($rowIssue['projects']) ? $rowIssue['projects'] : [];
              $isViewingRow = $archiveDate === $issueDate;
              $rowGenerated = '';
              if (!empty($rowIssue['generated_at'])) {
                  $rowTs = strtotime((string) $rowIssue['generated_at']);
                  if ($rowTs) {
                      $rowGenerated = date('Y.m.d H:i', $rowTs);
                  }
              }
              $rowKeywords = marginalia_format_date($archiveDate) . ' ' . marginalia_issue_padded($archiveDate) . ' '
                  . (string) ($rowIssue['theme'] ?? marginalia_theme($archiveDate));
              foreach ($rowProjects as $rowProject) {
                  $rowKeywords .= ' ' . (string) ($rowProject['title'] ?? '') . ' ' . (string) ($rowProject['source'] ?? '');
              }
              foreach (['news_cn', 'news_intl'] as $rowNewsKey) {
                  if (!empty($rowIssue[$rowNewsKey]) && is_array($rowIssue[$rowNewsKey])) {
                      foreach ($rowIssue[$rowNewsKey] as $rowNewsItem) {
                          $rowKeywords .= ' ' . (string) ($rowNewsItem['title'] ?? '') . ' ' . (string) ($rowNewsItem['source'] ?? '');
                      }
                  }
              }
              $rowKeywords = marginalia_truncate($rowKeywords, 1400);
              ?>
              <details class="archive-row" name="archive" data-keywords="<?= marginalia_esc($rowKeywords) ?>"<?= $isViewingRow ? ' open' : '' ?>>
                <summary>
                  <span class="row-no"><?= marginalia_esc(marginalia_issue_padded($archiveDate)) ?></span>
                  <span class="row-date"><?= marginalia_esc(marginalia_format_date($archiveDate)) ?></span>
                  <span class="row-title">
                    <?php if ($isViewingRow): ?>
                      <?= marginalia_esc((string) ($rowIssue['theme'] ?? marginalia_theme($archiveDate))) ?><span class="row-now">正在翻阅</span>
                    <?php else: ?>
                      <a class="row-title-link" href="<?= marginalia_esc(marginalia_issue_url($archiveDate)) ?>#today"><?= marginalia_esc((string) ($rowIssue['theme'] ?? marginalia_theme($archiveDate))) ?></a>
                    <?php endif; ?>
                  </span>
                  <span class="row-meta"><?= (int) ($rowCounts['projects'] ?? 0) ?> 处 · <?= $rowNewsTotal ?> 条</span>
                  <span class="row-icon" aria-hidden="true"></span>
                </summary>
                <div class="archive-panel">
                  <p class="archive-note"><?= marginalia_esc((string) ($rowIssue['theme'] ?? marginalia_theme($archiveDate))) ?>。<?php if ($rowGenerated !== ''): ?>自动汇总于 <?= marginalia_esc($rowGenerated) ?>。<?php endif; ?>以下是其中三处。</p>
                  <div class="archive-links">
                    <?php foreach (array_slice($rowProjects, 0, 3) as $rowProject): ?>
                      <a class="archive-link" href="<?= marginalia_esc($rowProject['url'] ?? '') ?>" target="_blank" rel="noopener noreferrer" data-random data-title="<?= marginalia_esc($rowProject['title'] ?? '') ?>">
                        <span class="al-title"><?= marginalia_esc($rowProject['title'] ?? '') ?></span>
                        <span class="al-desc"><?= marginalia_esc(marginalia_truncate((string) ($rowProject['description'] ?? ''), 70)) ?></span>
                        <span class="al-domain"><?= marginalia_esc($rowProject['domain'] ?? '') ?> ↗</span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                  <?php if ($isViewingRow): ?>
                    <span class="archive-more archive-more--current">正在翻阅这一期</span>
                  <?php else: ?>
                    <a class="archive-more" href="<?= marginalia_esc(marginalia_issue_url($archiveDate)) ?>#today">
                      查看这一期全部 <?= (int) ($rowCounts['projects'] ?? 0) ?> 处
                      <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                        <path d="M4 12 12 4M6 4h6v6"/>
                      </svg>
                    </a>
                  <?php endif; ?>
                </div>
              </details>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="archive-empty">这是第一期的存档。明天 <?= marginalia_esc($dropLabel) ?> 之后，这里会多出一行。</p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="closing" aria-labelledby="closing-title">
      <div class="shell">
        <div class="closing-grid">
          <div>
            <div class="section-folio">
              <span class="no">05</span>
              <span class="rule" aria-hidden="true"></span>
              <span>明天见</span>
            </div>
            <h2 id="closing-title">明天九点，<br>再来翻一页。</h2>
          </div>
          <div class="closing-side">
            <div class="closing-next">
              <span>下一期</span>
              <span class="closing-date" id="next-date"><?= marginalia_esc($nextDropLabel) ?></span>
            </div>
            <p class="closing-note">如果发现来源、版权或链接问题，写信告诉我们。这里没有投稿入口，二十处都由程序从公开来源汇总。</p>
            <a class="closing-mail" href="mailto:<?= marginalia_esc($email) ?>?subject=<?= rawurlencode('推荐一个网站') ?>">
              <?= marginalia_esc($email) ?>
              <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                <path d="M4 12 12 4M6 4h6v6"/>
              </svg>
            </a>
          </div>
        </div>
      </div>
    </section>
<?php endif; ?>
  </main>

  <footer class="footer">
    <div class="shell">
      <div class="footer-top">
        <a class="footer-brand" href="<?= marginalia_esc(marginalia_link('/#home')) ?>" aria-label="<?= marginalia_esc($siteName) ?>，回到顶部"><?= marginalia_esc($siteName) ?> <span><?= marginalia_esc($siteNameEn) ?></span></a>
        <nav class="footer-nav" aria-label="页脚导航">
          <a href="<?= marginalia_esc($navPrefix) ?>#today">本期</a>
          <a href="<?= marginalia_esc($navPrefix) ?>#news">新闻</a>
          <a href="<?= marginalia_esc($navPrefix) ?>#method">方法</a>
          <a href="<?= marginalia_esc($navPrefix) ?>#archive">存档</a>
          <a href="<?= marginalia_esc(marginalia_link('/feed.xml')) ?>">RSS</a>
          <a href="mailto:<?= marginalia_esc($email) ?>">写信</a>
        </nav>
      </div>
      <div class="footer-bottom">
        <p>© <?= date('Y') ?> <?= marginalia_esc($siteName) ?>。自动汇总，每日 <?= marginalia_esc($dropLabel) ?> 更新。本站无追踪、无广告。</p>
        <a href="<?= marginalia_esc($navPrefix) ?>#top">回到顶部 ↑</a>
      </div>
    </div>
  </footer>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
