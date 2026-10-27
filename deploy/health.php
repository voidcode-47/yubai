<?php
/**
 * 余白 MARGINALIA — 主机体检
 *
 *   health.php                  JSON 概览
 *   health.php?format=html      给人看的状态页
 *   health.php?probe=1          逐个访问来源，检查连通性（约 10 秒）
 *   health.php?probe=1&format=html
 *
 * 这个页面不会显示缓存目录的绝对路径，也不会显示 refresh key。
 */

require __DIR__ . '/lib.php';

@set_time_limit(50);

$format = isset($_GET['format']) ? strtolower((string) $_GET['format']) : 'json';
if ($format !== 'html') {
    $format = 'json';
}
$probe = isset($_GET['probe']) && (string) $_GET['probe'] === '1';

function marginalia_health_count_items(string $type, string $body): int
{
    if ($body === '') {
        return 0;
    }
    if ($type === 'hn') {
        $data = json_decode($body, true);
        return is_array($data) && isset($data['hits']) && is_array($data['hits']) ? count($data['hits']) : 0;
    }
    if ($type === 'github') {
        $data = json_decode($body, true);
        return is_array($data) && isset($data['items']) && is_array($data['items']) ? count($data['items']) : 0;
    }
    if ($type === 'github_trending') {
        return preg_match_all('~<article class="Box-row">~i', $body) ?: 0;
    }
    if (in_array($type, ['ph', 'awwwards', 'rss'], true)) {
        return count(marginalia_parse_feed($body));
    }
    return 0;
}

$data = marginalia_health_data(false);
unset($data['cache_dir']);

$latest = marginalia_latest_issue();
$data['archive_days'] = count(marginalia_archive_dates(0));
$data['refresh_key'] = (string) marginalia_config('refresh.key') === '' ? 'empty' : 'set';
$data['sources'] = $latest && !empty($latest['sources']) && is_array($latest['sources']) ? $latest['sources'] : [];
$data['issue_errors'] = $latest && !empty($latest['errors']) && is_array($latest['errors'])
    ? array_slice($latest['errors'], 0, 15)
    : [];

if ($probe) {
    $requests = [];
    $meta = [];
    $probeSince = marginalia_date_obj(marginalia_current_issue_date())->modify('-14 days')->format('Y-m-d');

    $projectSources = (array) marginalia_config('sources.projects');
    foreach ($projectSources as $key => $source) {
        if (empty($source['enabled'])) {
            continue;
        }
        $probeUrl = (string) $source['url'];
        if (($source['type'] ?? '') === 'github') {
            $probeUrl = str_replace('{since}', $probeSince, $probeUrl);
        }
        $requests['p_' . $key] = [
            'url'     => $probeUrl,
            'timeout' => 8,
        ];
        $meta['p_' . $key] = [
            'group' => '项目',
            'label' => (string) ($source['label'] ?? $key),
            'type'  => (string) ($source['type'] ?? ''),
        ];
    }

    $newsGroups = [
        'news_cn'   => '国内新闻',
        'news_intl' => '国际新闻',
    ];
    foreach ($newsGroups as $group => $labelGroup) {
        $sources = (array) marginalia_config('sources.' . $group);
        foreach ($sources as $key => $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            $requests['n_' . $key] = [
                'url'     => (string) $source['url'],
                'timeout' => 8,
            ];
            $meta['n_' . $key] = [
                'group' => $labelGroup,
                'label' => (string) ($source['label'] ?? $key),
                'type'  => (string) ($source['type'] ?? 'rss'),
            ];
        }
    }

    $responses = marginalia_http_multi($requests);
    $results = [];
    foreach ($meta as $key => $info) {
        $response = $responses[$key] ?? null;
        $body = $response && !empty($response['body']) ? (string) $response['body'] : '';
        $results[] = [
            'group'  => $info['group'],
            'label'  => $info['label'],
            'type'   => $info['type'],
            'ok'     => (bool) ($response['ok'] ?? false),
            'status' => (int) ($response['status'] ?? 0),
            'items'  => marginalia_health_count_items($info['type'], $body),
            'bytes'  => (int) ($response['bytes'] ?? 0),
            'ms'     => (int) ($response['ms'] ?? 0),
            'error'  => (string) ($response['error'] ?? ''),
        ];
    }
    $data['probe_at'] = marginalia_iso_now();
    $data['probe'] = $results;
}

if ($format === 'html') {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    $php = isset($data['php']) && is_array($data['php']) ? $data['php'] : [];
    $counts = $data['counts'] ?? null;
    $overall = !empty($data['ok']);
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23f5f4ed'/%3E%3Ccircle cx='32' cy='32' r='18' fill='none' stroke='%231b365d' stroke-width='1.5'/%3E%3Cpath d='M22 34h20' stroke='%231b365d' stroke-width='1.5'/%3E%3Ccircle cx='32' cy='24' r='1.8' fill='%231b365d'/%3E%3C/svg%3E">
  <title>主机体检｜余白 MARGINALIA</title>
  <style>
    :root { --paper:#f5f4ed; --paper-light:#fbfaf4; --ink:#1b365d; --text:#3a372f; --muted:#655e50; --faint:#736b59; --line:#dcd7c8; --line-soft:#e8e4d8; --ok:#2f6b4f; --warn:#8a4b2f; }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--paper); color:var(--text); font-family:"Georgia","Songti SC","SimSun",serif; line-height:1.75; }
    .shell { width:calc(100% - 44px); max-width:1040px; margin-inline:auto; }
    header { padding:34px 0 26px; border-bottom:1px solid var(--line); }
    .brand { display:flex; align-items:baseline; gap:12px; color:var(--ink); font-size:24px; letter-spacing:.1em; }
    .brand small { color:var(--faint); font-family:"Segoe UI",sans-serif; font-size:8px; letter-spacing:.22em; }
    h1 { margin:26px 0 8px; color:var(--ink); font-size:clamp(2rem,5vw,3.2rem); font-weight:400; line-height:1.2; }
    .lede { max-width:40em; margin:0; color:var(--muted); font-size:14px; }
    .actions { display:flex; flex-wrap:wrap; gap:16px; margin-top:22px; font-family:"Segoe UI",sans-serif; font-size:12px; }
    .actions a { color:var(--ink); border-bottom:1px solid rgba(27,54,93,.3); }
    main { padding:34px 0 70px; }
    h2 { margin:38px 0 14px; color:var(--ink); font-size:13px; font-family:"Segoe UI",sans-serif; font-weight:600; letter-spacing:.16em; text-transform:uppercase; }
    .status { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; border:1px solid var(--line); background:var(--line); }
    .cell { padding:16px 18px; background:var(--paper-light); }
    .cell span { display:block; color:var(--faint); font-family:"Segoe UI",sans-serif; font-size:9px; letter-spacing:.12em; }
    .cell strong { display:block; margin-top:6px; color:var(--ink); font-size:17px; font-weight:400; }
    .yes { color:var(--ok) !important; } .no { color:var(--warn) !important; }
    table { width:100%; border-collapse:collapse; font-family:"Segoe UI",sans-serif; font-size:11.5px; }
    th, td { padding:10px 12px; border-bottom:1px solid var(--line-soft); text-align:left; vertical-align:top; }
    th { color:var(--faint); font-size:9px; font-weight:500; letter-spacing:.1em; text-transform:uppercase; }
    td.num { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .pill { display:inline-block; padding:1px 6px; border:1px solid var(--line); border-radius:2px; font-size:9px; letter-spacing:.06em; }
    .pill.ok { border-color:#bcd3c5; color:var(--ok); }
    .pill.bad { border-color:#e2cabc; color:var(--warn); }
    .meta { color:var(--faint); font-size:10px; }
    .errors { margin:0; padding-left:18px; color:var(--warn); font-size:12.5px; }
    .errors li { margin:4px 0; }
    footer { padding:24px 0 42px; border-top:1px solid var(--line-soft); color:var(--faint); font-family:"Segoe UI",sans-serif; font-size:10px; }
    code { font-family:Consolas,monospace; font-size:.92em; color:var(--ink); }
    @media (max-width:760px) { .status { grid-template-columns:1fr 1fr; } th:nth-child(5), td:nth-child(5) { display:none; } }
  </style>
</head>
<body>
  <header>
    <div class="shell">
      <div class="brand">余白 <small>MARGINALIA · HEALTH</small></div>
      <h1>主机体检</h1>
      <p class="lede">给部署的人看的一页：PHP 扩展、缓存写入、来源连通性和最近一次抓取结果。这个页面不会被搜索引擎收录。</p>
      <div class="actions">
        <a href="?format=html&amp;probe=1">重新检查来源</a>
        <a href="?format=html">只看本地状态</a>
        <a href="./">回到余白</a>
      </div>
    </div>
  </header>
  <main class="shell">
    <h2>总体</h2>
    <div class="status">
      <div class="cell"><span>状态</span><strong class="<?= $overall ? 'yes' : 'no' ?>"><?= $overall ? '可以运行' : '需要检查' ?></strong></div>
      <div class="cell"><span>最新一期</span><strong><?= marginalia_esc((string) ($data['latest_issue'] ?? '还没有')) ?></strong></div>
      <div class="cell"><span>缓存写入</span><strong class="<?= !empty($data['cache_writable']) ? 'yes' : 'no' ?>"><?= !empty($data['cache_writable']) ? '可写' : '不可写' ?></strong></div>
      <div class="cell"><span>存档</span><strong><?= (int) ($data['archive_days'] ?? 0) ?> 天</strong></div>
    </div>

    <h2>运行环境</h2>
    <table>
      <tbody>
        <tr><th>PHP</th><td><?= marginalia_esc((string) ($php['version'] ?? '')) ?> · <?= marginalia_esc((string) ($php['sapi'] ?? '')) ?></td></tr>
        <tr><th>cURL / OpenSSL</th><td><?= !empty($php['curl']) ? '有' : '无' ?> / <?= !empty($php['openssl']) ? '有' : '无' ?> <?= marginalia_esc((string) ($php['curl_version'] ?? '')) ?></td></tr>
        <tr><th>抓取模式</th><td><?= ($data['fetch_mode'] ?? '') === 'parallel' ? '并行' : '顺序（主机禁用了 curl_multi）' ?></td></tr>
        <tr><th>SimpleXML / mbstring</th><td><?= !empty($php['simplexml']) ? '有' : '无' ?> / <?= !empty($php['mbstring']) ? '有' : '无' ?></td></tr>
        <tr><th>CA 证书</th><td><?= !empty($data['ca_bundle']) ? '已找到' : '缺失' ?></td></tr>
        <tr><th>时区 / 现在</th><td><?= marginalia_esc((string) ($data['timezone'] ?? '')) ?> · <?= marginalia_esc((string) ($data['now'] ?? '')) ?></td></tr>
        <tr><th>下一期</th><td><?= marginalia_esc((string) ($data['next_drop_iso'] ?? '')) ?></td></tr>
        <tr><th>refresh key</th><td><?= ($data['refresh_key'] ?? '') === 'set' ? '已设置' : '<span class="no">为空（force 已关闭）</span>' ?></td></tr>
      </tbody>
    </table>

    <h2>最近一期</h2>
    <table>
      <tbody>
        <tr><th>日期 / 期号</th><td><?= marginalia_esc((string) ($data['issue_date'] ?? '')) ?> · 第 <?= marginalia_esc((string) ($latest['issue'] ?? '?')) ?> 期</td></tr>
        <tr><th>生成时间</th><td><?= marginalia_esc((string) ($data['generated_at'] ?? '')) ?> <?= !empty($data['build_ms']) ? '· ' . (int) $data['build_ms'] . ' ms' : '' ?></td></tr>
        <tr><th>数量</th><td><?= is_array($counts) ? (int) ($counts['projects'] ?? 0) . ' 处 · 国内 ' . (int) ($counts['news_cn'] ?? 0) . ' 条 · 国际 ' . (int) ($counts['news_intl'] ?? 0) . ' 条' : '还没有内容' ?></td></tr>
        <tr><th>降级</th><td><?= !empty($data['degraded']) ? '<span class="no">是，部分来源没连上</span>' : ($data['degraded'] === false ? '否' : '未知') ?></td></tr>
      </tbody>
    </table>

    <?php if (!empty($data['sources'])): ?>
      <h2>上次抓取</h2>
      <table>
        <thead><tr><th>来源</th><th>结果</th><th>条目</th><th>耗时</th><th>错误</th></tr></thead>
        <tbody>
          <?php foreach ($data['sources'] as $source): ?>
            <?php
            $sourceError = trim((string) ($source['error'] ?? ''));
            if ($sourceError === '' && empty($source['ok'])) {
                $sourceError = (int) ($source['status'] ?? 0) === 0
                    ? '无响应或超时'
                    : 'HTTP ' . (int) ($source['status'] ?? 0);
            }
            ?>
            <tr>
              <td><?= marginalia_esc((string) ($source['label'] ?? '')) ?></td>
              <td><span class="pill <?= !empty($source['ok']) ? 'ok' : 'bad' ?>"><?= !empty($source['ok']) ? 'HTTP ' . (int) ($source['status'] ?? 0) : '失败' ?></span></td>
              <td class="num"><?= (int) ($source['items'] ?? 0) ?></td>
              <td class="num"><?= (int) ($source['ms'] ?? 0) ?> ms</td>
              <td class="meta"><?= marginalia_esc($sourceError) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (!empty($data['probe'])): ?>
      <h2>来源连通性 · <?= marginalia_esc((string) ($data['probe_at'] ?? '')) ?></h2>
      <table>
        <thead><tr><th>分组 / 来源</th><th>结果</th><th>条目</th><th>大小 / 耗时</th><th>错误</th></tr></thead>
        <tbody>
          <?php foreach ($data['probe'] as $source): ?>
            <?php
            $probeError = trim((string) ($source['error'] ?? ''));
            if ($probeError === '' && empty($source['ok'])) {
                $probeError = (int) ($source['status'] ?? 0) === 0
                    ? '无响应或超时'
                    : 'HTTP ' . (int) ($source['status'] ?? 0);
            }
            ?>
            <tr>
              <td><?= marginalia_esc((string) ($source['group'] ?? '')) ?> · <?= marginalia_esc((string) ($source['label'] ?? '')) ?></td>
              <td><span class="pill <?= !empty($source['ok']) ? 'ok' : 'bad' ?>"><?= !empty($source['ok']) ? 'HTTP ' . (int) ($source['status'] ?? 0) : '失败' ?></span></td>
              <td class="num"><?= (int) ($source['items'] ?? 0) ?></td>
              <td class="num"><?= number_format((int) ($source['bytes'] ?? 0)) ?> B · <?= (int) ($source['ms'] ?? 0) ?> ms</td>
              <td class="meta"><?= marginalia_esc($probeError) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (!empty($data['issue_errors'])): ?>
      <h2>最近一期的问题</h2>
      <ul class="errors">
        <?php foreach ($data['issue_errors'] as $error): ?>
          <li><?= marginalia_esc($error) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($data['last_failure'])): ?>
      <h2>最近一次失败记录</h2>
      <p class="meta"><code><?= marginalia_esc(json_encode($data['last_failure'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></p>
    <?php endif; ?>
  </main>
  <footer>
    <div class="shell">余白 MARGINALIA · 这一页只用于部署检查，不需要对读者公开。</div>
  </footer>
</body>
</html>
    <?php
    exit;
}

marginalia_json_response($data, 200);
