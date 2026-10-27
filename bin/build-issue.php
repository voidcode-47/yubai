<?php
/**
 * 命令行构建当天这一期。
 *
 * 供 GitHub Actions 使用：抓取 → 写 deploy/cache/issue-YYYY-MM-DD.json。
 * 不经过 HTTP，所以没有主机执行时间限制之外的问题；
 * 依赖的抓取预算仍由 config.php 的 http.* 控制。
 *
 * 用法：
 *   php bin/build-issue.php           # 当天已有缓存就跳过
 *   php bin/build-issue.php --force   # 强制重抓
 */

require_once __DIR__ . '/../deploy/lib.php';

@set_time_limit(0);

$force = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    }
}

$date = marginalia_current_issue_date();
$issue = marginalia_ensure_issue($date, $force);

if (!is_array($issue)) {
    fwrite(STDERR, "build failed: {$date}\n");
    exit(1);
}

$summary = [
    'date'       => (string) ($issue['date'] ?? $date),
    'issue'      => (int) ($issue['issue'] ?? 0),
    'theme'      => (string) ($issue['theme'] ?? ''),
    'degraded'   => (bool) ($issue['degraded'] ?? false),
    'counts'     => $issue['counts'] ?? [],
    'errors'     => $issue['errors'] ?? [],
    'generated'  => (string) ($issue['generated_at'] ?? ''),
    'build_ms'   => (int) ($issue['build_ms'] ?? 0),
];

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

// 降级也照常发布：少几条比整期空白好，页面顶部会标注。
exit(0);
