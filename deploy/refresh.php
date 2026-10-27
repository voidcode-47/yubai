<?php
/**
 * 余白 MARGINALIA — 刷新端点
 *
 * 用法：
 *   refresh.php?auto=1              页面加载后自动调用；只在缺期、降级或冷却结束时才真的抓取
 *   refresh.php?key=你的密钥        外部 cron 调用；缺期时构建，已有则直接返回
 *   refresh.php?key=你的密钥&force=1 强制重抓当天
 *
 * 配置里的 refresh.key 为空时，force 不设防；上线前建议改成随机字符串。
 */

require __DIR__ . '/lib.php';

@set_time_limit(50);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: GET, POST');
    }
    marginalia_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$auto = isset($_GET['auto']) && (string) $_GET['auto'] === '1';
$force = isset($_GET['force']) && (string) $_GET['force'] === '1';

if ($force && (string) marginalia_config('refresh.key') === '') {
    marginalia_json_response([
        'ok'      => false,
        'error'   => 'force_disabled',
        'message' => '强制刷新默认关闭。请先在 config.php 里设置 refresh.key，再用 &key= 调用。',
    ], 403);
}

if ($force && !marginalia_request_key_ok()) {
    marginalia_json_response([
        'ok'      => false,
        'error'   => 'forbidden',
        'message' => '强制刷新需要正确的 key。',
    ], 403);
}

if (!$auto && !$force && !marginalia_request_key_ok()) {
    marginalia_json_response([
        'ok'      => false,
        'error'   => 'forbidden',
        'message' => '这个端点需要 key；页面自动刷新请用 ?auto=1。',
    ], 403);
}

$date = marginalia_current_issue_date();
$existing = marginalia_load_issue($date);
$issue = $existing;
$built = false;
$reason = 'fresh';

if ($force) {
    $result = marginalia_build_issue($date, true);
    if ($result) {
        $issue = $result;
        $built = true;
        $reason = 'forced';
    } else {
        $reason = 'build_failed';
    }
} elseif (!$existing) {
    $result = marginalia_ensure_issue($date);
    if ($result) {
        $issue = $result;
        $built = true;
        $reason = 'missing';
    } else {
        $reason = 'build_failed';
    }
} elseif (!empty($existing['degraded'])) {
    $lastAttempt = 0;
    if (!empty($existing['generated_at'])) {
        $parsed = strtotime((string) $existing['generated_at']);
        if ($parsed) {
            $lastAttempt = (int) $parsed;
        }
    }
    $fail = marginalia_read_json(marginalia_cache_dir() . '/fail-' . $date . '.json');
    if (is_array($fail) && !empty($fail['at'])) {
        $lastAttempt = max($lastAttempt, (int) $fail['at']);
    }
    $cooldown = max(60, (int) marginalia_config('cache.fail_cooldown'));

    if ($lastAttempt === 0 || time() - $lastAttempt >= $cooldown) {
        $result = marginalia_build_issue($date, true);
        if ($result) {
            $issue = $result;
            $built = true;
            $reason = 'degraded_retry';
        } else {
            $reason = 'build_failed';
        }
    } else {
        $reason = 'cooldown';
    }
}

$ok = is_array($issue);
$payload = [
    'ok'           => $ok,
    'built'        => $built,
    'reason'       => $reason,
    'auto'         => $auto,
    'date'         => $date,
    'issue'        => $issue ? (int) ($issue['issue'] ?? marginalia_issue_number($date)) : null,
    'counts'       => $issue ? ($issue['counts'] ?? null) : null,
    'degraded'     => $issue ? (bool) ($issue['degraded'] ?? false) : null,
    'generated_at' => $issue ? ($issue['generated_at'] ?? null) : null,
    'build_ms'     => $issue ? ($issue['build_ms'] ?? null) : null,
    'now'          => marginalia_iso_now(),
];

if (!$ok) {
    $payload['message'] = '这一期还没有构建成功。请检查 cache/ 写权限，并打开 health.php 查看来源连通性。';
}

marginalia_json_response($payload, $ok ? 200 : 503);
