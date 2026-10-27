<?php
/**
 * 冷启动过渡页。
 *
 * 只在当天缓存还不存在、且没有处在失败冷却里时由 index.php 引入：
 * 先把这一页发给访客，页面自己调 refresh.php?auto=1 构建，完成后再进入首页。
 * 直接访问 loading.php 会回到首页。
 */

if (!defined('MARGINALIA_LOADING')) {
    header('Location: ./', true, 302);
    exit;
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Retry-After: 15');
}

$latestDate = isset($loadingLatestDate) ? (string) $loadingLatestDate : '';
$latestLabel = $latestDate !== '' ? marginalia_format_date($latestDate) : '';
$latestNo = $latestDate !== '' ? marginalia_issue_padded($latestDate) : '';
$issueDate = isset($currentIssueDate) ? (string) $currentIssueDate : marginalia_current_issue_date();
$todayLabel = marginalia_format_date($issueDate);
$todayWeekday = marginalia_weekday($issueDate);
$issuePadded = isset($loadingIssuePadded) ? (string) $loadingIssuePadded : marginalia_issue_padded($issueDate);
$dropLabel = isset($loadingDropLabel) ? (string) $loadingDropLabel : '09:00';
$styleVersion = isset($loadingStyleVersion) ? (string) $loadingStyleVersion : '1';
$siteName = isset($loadingSiteName) ? (string) $loadingSiteName : '余白';
$siteNameEn = isset($loadingSiteNameEn) ? (string) $loadingSiteNameEn : 'MARGINALIA';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>正在整理今天的余白｜<?= marginalia_esc($siteName) ?> <?= marginalia_esc($siteNameEn) ?></title>
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#f5f4ed">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23f5f4ed'/%3E%3Ccircle cx='32' cy='32' r='18' fill='none' stroke='%231b365d' stroke-width='1.5'/%3E%3Cpath d='M22 34h20' stroke='%231b365d' stroke-width='1.5'/%3E%3Ccircle cx='32' cy='24' r='1.8' fill='%231b365d'/%3E%3C/svg%3E">
  <link rel="preload" href="<?= marginalia_esc(marginalia_link('/assets/fonts/newsreader-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= marginalia_esc(marginalia_link('/assets/fonts/dmsans-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= marginalia_esc(marginalia_link('/assets/style.css')) ?>?v=<?= marginalia_esc($styleVersion) ?>">
</head>
<body class="is-loading" data-issue-date="<?= marginalia_esc($issueDate) ?>" data-latest-date="<?= marginalia_esc($latestDate) ?>">
  <div class="grain" aria-hidden="true"></div>

  <main class="loading" id="loading">
    <div class="shell loading-inner">
      <div class="loading-top">
        <a class="loading-brand" href="<?= marginalia_esc(marginalia_link('/')) ?>"><?= marginalia_esc($siteName) ?> <span><?= marginalia_esc($siteNameEn) ?></span></a>
        <span class="loading-issue">第 <?= marginalia_esc($issuePadded) ?> 期</span>
      </div>

      <div class="loading-body">
        <p class="loading-kicker"><?= marginalia_esc($todayLabel) ?> <?= marginalia_esc($todayWeekday) ?> · 每日 <?= marginalia_esc($dropLabel) ?> 更新</p>
        <h1>正在整理<br>今天的二十处。</h1>
        <p class="loading-status" id="loading-status" role="status" aria-live="polite">第一次打开会多等几秒。正在抓取公开来源，通常需要 8–15 秒，完成后会自动进入。</p>
        <div class="loading-bar" id="loading-bar" aria-hidden="true"><span></span></div>

        <div class="loading-fallback" id="loading-fallback" hidden>
          <p>来源偶尔会一起掉线，晚一点再回来通常会好。</p>
          <div class="loading-actions">
            <a class="btn-primary" href="<?= marginalia_esc(marginalia_link('/?fallback=1')) ?>">先看常青拾遗</a>
            <?php if ($latestDate !== ''): ?>
              <a class="loading-prev" href="<?= marginalia_esc(marginalia_issue_url($latestDate)) ?>">翻第 <?= marginalia_esc($latestNo) ?> 期 · <?= marginalia_esc($latestLabel) ?></a>
            <?php endif; ?>
          </div>
        </div>

        <noscript>
          <p class="loading-status">浏览器没有开启脚本，无法自动抓取。可以<a href="<?= marginalia_esc(marginalia_link('/?fallback=1')) ?>">先看常青拾遗</a>，或稍后手动刷新。</p>
        </noscript>
      </div>

      <p class="loading-foot">余白每天只整理一页。没有无限滚动，也没有推荐流。</p>
    </div>
  </main>

  <script>
  (() => {
    "use strict";

    const status = document.getElementById("loading-status");
    const fallback = document.getElementById("loading-fallback");
    const bar = document.getElementById("loading-bar");
    const setStatus = (text) => { if (status) status.textContent = text; };
    let attempts = 0;

    const enter = () => window.location.replace("./");

    const attempt = async () => {
      attempts += 1;
      if (attempts > 1) {
        setStatus("还在整理。来源有时回得慢，再等一会儿…");
      }
      try {
        const response = await fetch(<?= json_encode(marginalia_link('/refresh.php?auto=1')) ?> + "&_=" + Date.now(), {
          cache: "no-store",
          credentials: "same-origin",
          headers: { "Accept": "application/json" }
        });
        if (response.ok) {
          const result = await response.json().catch(() => null);
          if (result && result.ok) {
            enter();
            return;
          }
        }
      } catch (error) {
        // 网络暂时不可达，继续重试。
      }

      if (attempts >= 3) {
        if (bar) bar.hidden = true;
        if (fallback) fallback.hidden = false;
        setStatus("来源这次没有回应。");
        return;
      }
      window.setTimeout(attempt, 2500);
    };

    attempt();
  })();
  </script>
</body>
</html>
