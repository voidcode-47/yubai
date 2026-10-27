<?php
/**
 * 余白 MARGINALIA — 抓取、缓存与渲染辅助
 *
 * 目标环境：PHP 7.4+ / 8.x，Apache，VistaPanel 免费主机。
 * 不依赖数据库；只用文件缓存。cURL 不可用时退回 file_get_contents。
 */

marginalia_setup();

function marginalia_setup(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $timezone = marginalia_config('site.timezone');
    if ($timezone) {
        date_default_timezone_set($timezone);
    }

    $cache = marginalia_cache_dir();
    if (!is_dir($cache)) {
        @mkdir($cache, 0775, true);
    }

    error_reporting(E_ALL);
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    @ini_set('error_log', $cache . '/error.log');

    $done = true;
}

function marginalia_config($path = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    if ($path === null || $path === '') {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function marginalia_root(): string
{
    return __DIR__;
}

function marginalia_cache_dir(): string
{
    $dir = (string) marginalia_config('cache.dir');
    return rtrim($dir, "/\\");
}

function marginalia_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone((string) marginalia_config('site.timezone'));
    }
    return $tz;
}

function marginalia_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', marginalia_tz());
}

function marginalia_iso_now(): string
{
    return marginalia_now()->format(DateTime::ATOM);
}

function marginalia_log(string $message): void
{
    if (!marginalia_config('cache.write_json_log')) {
        return;
    }
    $file = marginalia_cache_dir() . '/error.log';
    if (is_file($file) && filesize($file) > 262144) {
        @file_put_contents($file, '');
    }
    $line = '[' . marginalia_iso_now() . '] ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function marginalia_esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function marginalia_atomic_write(string $path, string $content): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
    }
    return true;
}

function marginalia_read_json(string $path)
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function marginalia_json_flags(): int
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

/* -------------------------------------------------------------------------
 * 日期与期号
 * ---------------------------------------------------------------------- */

function marginalia_today_date(): string
{
    return marginalia_now()->format('Y-m-d');
}

function marginalia_current_issue_date(): string
{
    $now = marginalia_now();
    $drop = $now->setTime((int) marginalia_config('site.drop_hour'), 0, 0);
    if ($now < $drop) {
        $now = $now->modify('-1 day');
    }
    return $now->format('Y-m-d');
}

function marginalia_next_drop_ts(): int
{
    $now = time();
    $today = new DateTimeImmutable('today', marginalia_tz());
    $drop = $today->setTime((int) marginalia_config('site.drop_hour'), 0, 0);
    if ($drop->getTimestamp() <= $now) {
        $drop = $drop->modify('+1 day');
    }
    return $drop->getTimestamp();
}

function marginalia_date_obj(string $date): DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, marginalia_tz());
    if (!$d) {
        $d = new DateTimeImmutable($date, marginalia_tz());
    }
    return $d;
}

function marginalia_format_date(string $date): string
{
    return marginalia_date_obj($date)->format('Y.m.d');
}

function marginalia_weekday(string $date): string
{
    $names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
    $index = (int) marginalia_date_obj($date)->format('w');
    return $names[$index] ?? '';
}

function marginalia_date_cn(string $date): string
{
    return marginalia_format_date($date) . ' ' . marginalia_weekday($date);
}

function marginalia_meta(): array
{
    $meta = marginalia_read_json(marginalia_cache_dir() . '/meta.json');
    return is_array($meta) ? $meta : [];
}

function marginalia_meta_save(array $meta): void
{
    marginalia_atomic_write(
        marginalia_cache_dir() . '/meta.json',
        json_encode($meta, marginalia_json_flags())
    );
}

function marginalia_issue_number(string $date): int
{
    $meta = marginalia_meta();
    $epoch = isset($meta['epoch']) ? (string) $meta['epoch'] : '';
    if ($epoch === '') {
        return 1;
    }
    $days = (int) marginalia_date_obj($epoch)->diff(marginalia_date_obj($date))->format('%r%a');
    return max(1, $days + 1);
}

function marginalia_issue_padded(string $date): string
{
    return str_pad((string) marginalia_issue_number($date), 3, '0', STR_PAD_LEFT);
}

function marginalia_theme(string $date): string
{
    // 期号主题不再随机抽取：标清楚这一期是怎么来的，读者能看懂，也不会假装有编辑意图。
    return marginalia_weekday($date) . ' · 常规抓取';
}

function marginalia_issue_path(string $date): string
{
    return marginalia_cache_dir() . '/issue-' . $date . '.json';
}

/**
 * 往期的固定地址。用绝对路径：从 /issue/<日期>/ 打开时，
 * 相对路径会被解析成 /issue/<日期>/issue/...，反而失效。
 */
function marginalia_base_path(): string
{
    $path = trim((string) marginalia_config('site.base_path'));
    if ($path === '' || $path === '/') {
        return '';
    }
    return '/' . trim($path, '/');
}

/**
 * 站内链接。默认是根目录；托管在子目录（例如 GitHub Pages 的 /yubai/）时，
 * 由 site.base_path 统一加前缀，模板里就不用到处写相对路径。
 */
function marginalia_link(string $path = '/'): string
{
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $path)) {
        return $path;
    }
    $base = marginalia_base_path();
    if ($path === '') {
        $path = '/';
    }
    if ($path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base . $path;
}

/** 带域名和子目录的绝对地址，用于 canonical、og:image、RSS。 */
function marginalia_abs(string $path = '/'): string
{
    $origin = rtrim((string) marginalia_config('site.url'), '/');
    return $origin . marginalia_link($path);
}

function marginalia_issue_url(string $date): string
{
    return marginalia_link('/issue/' . rawurlencode($date) . '/');
}

function marginalia_load_issue(string $date)
{
    $issue = marginalia_read_json(marginalia_issue_path($date));
    if (!is_array($issue) || empty($issue['date'])) {
        return null;
    }
    return $issue;
}

function marginalia_latest_issue_date()
{
    $dates = marginalia_archive_dates(1);
    return $dates ? $dates[0] : null;
}

/**
 * 当天抓取失败后的冷却是否还在生效。
 * 只读 fail-YYYY-MM-DD.json，不触发抓取；用于首页判断该给“正在整理”还是直接给兜底页。
 */
function marginalia_build_cooldown_active(string $date, ?int $now = null): bool
{
    $fail = marginalia_read_json(marginalia_cache_dir() . '/fail-' . $date . '.json');
    if (!is_array($fail) || empty($fail['at'])) {
        return false;
    }
    $cooldown = max(60, (int) marginalia_config('cache.fail_cooldown'));
    return (($now ?? time()) - (int) $fail['at']) < $cooldown;
}

function marginalia_latest_issue()
{
    $date = marginalia_latest_issue_date();
    return $date ? marginalia_load_issue($date) : null;
}

function marginalia_archive_dates(int $limit = 30): array
{
    $files = glob(marginalia_cache_dir() . '/issue-????-??-??.json');
    if (!$files) {
        return [];
    }
    $dates = [];
    foreach ($files as $file) {
        if (preg_match('/issue-(\d{4}-\d{2}-\d{2})\.json$/', $file, $m)) {
            $dates[] = $m[1];
        }
    }
    rsort($dates, SORT_STRING);
    $dates = array_values(array_unique($dates));
    return $limit > 0 ? array_slice($dates, 0, $limit) : $dates;
}

function marginalia_prune_issues(): void
{
    $keep = max(1, (int) marginalia_config('cache.keep_days'));
    $dates = marginalia_archive_dates(0);
    if (count($dates) <= $keep) {
        return;
    }
    foreach (array_slice($dates, $keep) as $date) {
        @unlink(marginalia_issue_path($date));
    }
}

/* -------------------------------------------------------------------------
 * 文本、URL 与来源元数据
 * ---------------------------------------------------------------------- */

function marginalia_fix_encoding(string $raw): string
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!is_string($raw)) {
        $raw = '';
    }

    if (preg_match('/encoding=["\']([^"\']+)/i', substr($raw, 0, 400), $m)) {
        $enc = strtoupper($m[1]);
        if (!in_array($enc, ['UTF-8', 'UTF8'], true)) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', $enc);
                if (is_string($converted)) {
                    $raw = $converted;
                }
            } elseif (function_exists('iconv')) {
                $converted = @iconv($enc, 'UTF-8//IGNORE', $raw);
                if (is_string($converted)) {
                    $raw = $converted;
                }
            }
            $raw = preg_replace('/encoding=["\'][^"\']+/i', 'encoding="UTF-8"', $raw, 1);
        }
    }

    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
            if (is_string($converted)) {
                $raw = $converted;
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $raw);
            if (is_string($converted)) {
                $raw = $converted;
            }
        }
    }

    return $raw;
}

function marginalia_clean_text($value): string
{
    $text = (string) $value;
    if ($text === '') {
        return '';
    }
    $text = marginalia_fix_encoding($text);
    $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text);
    $text = preg_replace('~<br\s*/?>~i', ' ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    if (!is_string($text)) {
        $text = strip_tags((string) $value);
    }
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim(is_string($text) ? $text : '');
}

function marginalia_strlen(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function marginalia_truncate(string $text, int $limit): string
{
    $text = trim($text);
    if ($text === '' || $limit <= 0) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
    }
    if (strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(substr($text, 0, $limit - 1)) . '…';
}

function marginalia_desc_lang(string $text): string
{
    if (preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text, $matches)) {
        return count($matches[0]) >= 4 ? 'zh' : 'en';
    }
    return 'en';
}

function marginalia_normalize_url($url, string $base = ''): string
{
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }
    if ($base !== '' && strpos($url, 'http') !== 0) {
        $baseParts = parse_url($base);
        if ($baseParts && !empty($baseParts['scheme']) && !empty($baseParts['host'])) {
            if (strpos($url, '/') === 0) {
                $url = $baseParts['scheme'] . '://' . $baseParts['host'] . $url;
            } else {
                $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
                $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
                $url = $baseParts['scheme'] . '://' . $baseParts['host'] . $dir . '/' . $url;
            }
        }
    }

    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $host = strtolower($parts['host']);
    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

    $query = '';
    if (isset($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $params);
        foreach (array_keys($params) as $key) {
            if (preg_match('/^(utm_|fbclid$|gclid$|mc_cid$|mc_eid$)/i', (string) $key)) {
                unset($params[$key]);
            }
        }
        if ($params) {
            $query = http_build_query($params);
        }
    }

    $result = $scheme . '://' . $host;
    if (!empty($parts['port'])) {
        $result .= ':' . (int) $parts['port'];
    }
    $result .= $path;
    if ($query !== '') {
        $result .= '?' . $query;
    }
    return $result;
}

function marginalia_url_key(string $url): string
{
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
    return $host . $path;
}

function marginalia_diversity_key(string $url): string
{
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
    if ($host === 'github.com') {
        $segments = explode('/', $path);
        return 'github.com/' . strtolower($segments[0] ?? '');
    }
    if ($host === 'producthunt.com') {
        return 'producthunt.com/' . $path;
    }
    return $host;
}

function marginalia_domain_label(string $url): string
{
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
    $segments = $path === '' ? [] : explode('/', $path);

    if ($host === 'github.com' && count($segments) >= 2) {
        return $host . '/' . $segments[0] . '/' . $segments[1];
    }
    if ($host === 'producthunt.com' && count($segments) >= 2 && $segments[0] === 'products') {
        return $host . '/products/' . $segments[1];
    }
    return $host;
}

function marginalia_is_blocked_url(string $url): bool
{
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return true;
    }
    $host = strtolower($parts['host']);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $blocked = marginalia_config('filters.blocked_domains');
    if (!is_array($blocked)) {
        return false;
    }
    foreach ($blocked as $domain) {
        $domain = strtolower((string) $domain);
        if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
            return true;
        }
    }
    return false;
}

function marginalia_has_word(string $text, array $words): bool
{
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    foreach ($words as $word) {
        $word = function_exists('mb_strtolower') ? mb_strtolower((string) $word, 'UTF-8') : strtolower((string) $word);
        if ($word !== '' && strpos($text, $word) !== false) {
            return true;
        }
    }
    return false;
}

function marginalia_parse_date($value): int
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false || $timestamp <= 0) {
        return 0;
    }
    if ($timestamp > time() + 7200) {
        return time();
    }
    return $timestamp;
}

function marginalia_format_number(int $number): string
{
    if ($number >= 1000000) {
        return rtrim(rtrim(number_format($number / 1000000, 1, '.', ''), '0'), '.') . 'm';
    }
    if ($number >= 1000) {
        return rtrim(rtrim(number_format($number / 1000, 1, '.', ''), '0'), '.') . 'k';
    }
    return (string) $number;
}

function marginalia_source_meta(string $key): array
{
    $map = [
        'hn'       => ['label' => 'Hacker News', 'badge' => 'HN'],
        'github'   => ['label' => 'GitHub', 'badge' => 'GH'],
        'ph'       => ['label' => 'Product Hunt', 'badge' => 'PH'],
        'awwwards' => ['label' => 'Awwwards', 'badge' => 'AWD'],
        'seed'     => ['label' => '常青拾遗', 'badge' => '拾遗'],
    ];
    return $map[$key] ?? ['label' => $key, 'badge' => strtoupper(substr($key, 0, 3))];
}

function marginalia_glyph(int $number): string
{
    $number = (($number - 1) % 8) + 1;
    $glyphs = [
        1 => '<circle cx="16" cy="16" r="4.2"/><path d="M16 3v5M16 24v5M3 16h5M24 16h5M6.8 6.8l3.5 3.5M21.7 21.7l3.5 3.5M25.2 6.8l-3.5 3.5M10.3 21.7l-3.5 3.5"/>',
        2 => '<path d="M6 22v-6M11 26V6M16 21V11M21 26V9M26 20v-8"/>',
        3 => '<rect x="5" y="7" width="22" height="18" rx="1.5"/><path d="M5 12h22M12 12v13"/>',
        4 => '<path d="M6 25h20M9 25V13m14 12V13M6 13l10-6 10 6M13 25v-8h6v8"/>',
        5 => '<path d="M4 24h24M8 24c0-5 3-8 8-8s8 3 8 8"/><circle cx="12" cy="8" r=".9" fill="currentColor" stroke="none"/><circle cx="18" cy="5.5" r=".7" fill="currentColor" stroke="none"/><circle cx="22.5" cy="10" r=".6" fill="currentColor" stroke="none"/>',
        6 => '<circle cx="16" cy="16" r="11"/><path d="m20.5 11.5-3 6-6 3 3-6 6-3Z"/>',
        7 => '<rect x="5" y="5" width="8" height="8" rx="1"/><rect x="19" y="5" width="8" height="8" rx="1"/><rect x="5" y="19" width="8" height="8" rx="1"/><rect x="19" y="19" width="8" height="8" rx="1"/>',
        8 => '<circle cx="16" cy="16" r="4"/><ellipse cx="16" cy="16" rx="13" ry="5.5" transform="rotate(-30 16 16)"/><circle cx="27" cy="10" r="1.4" fill="currentColor" stroke="none"/>',
    ];
    return $glyphs[$number] ?? $glyphs[1];
}

/* -------------------------------------------------------------------------
 * HTTP
 * ---------------------------------------------------------------------- */

function marginalia_http_headers(array $extra = []): array
{
    $headers = [
        'Accept: application/json, application/atom+xml, application/rss+xml, application/xml, text/xml, */*;q=0.8',
        'Accept-Language: ' . (string) marginalia_config('http.accept_language'),
        'Cache-Control: no-cache',
    ];
    foreach ($extra as $header) {
        $headers[] = $header;
    }
    return $headers;
}

function marginalia_curl_options(string $url, float $timeout): array
{
    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_CONNECTTIMEOUT => (int) marginalia_config('http.connect_timeout'),
        CURLOPT_TIMEOUT        => (int) ceil($timeout),
        CURLOPT_USERAGENT      => (string) marginalia_config('http.user_agent'),
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => marginalia_http_headers(),
        CURLOPT_HEADER         => false,
    ];
    if (defined('CURLOPT_ACCEPT_ENCODING')) {
        $options[CURLOPT_ACCEPT_ENCODING] = '';
    }
    $ca = (string) marginalia_config('http.ca_bundle');
    if ($ca !== '' && is_file($ca)) {
        $options[CURLOPT_CAINFO] = $ca;
    }
    return $options;
}

function marginalia_curl_multi_available(): bool
{
    foreach ([
        'curl_multi_init',
        'curl_multi_add_handle',
        'curl_multi_exec',
        'curl_multi_select',
        'curl_multi_getcontent',
        'curl_multi_remove_handle',
        'curl_multi_close',
    ] as $fn) {
        if (!function_exists($fn)) {
            return false;
        }
    }
    return true;
}

/**
 * 免费主机（例如 InfinityFree / VistaPanel）常用 disable_functions 关掉 curl_multi_*。
 * 这时按顺序抓取，并严格遵守总时间预算，绝不让页面因为超时或致命错误变成 500。
 */
function marginalia_build_deadline_set(float $seconds): void
{
    $GLOBALS['marginalia_build_deadline'] = microtime(true) + max(1.0, $seconds);
}

function marginalia_build_deadline_left(): ?float
{
    if (!isset($GLOBALS['marginalia_build_deadline'])) {
        return null;
    }
    return (float) $GLOBALS['marginalia_build_deadline'] - microtime(true);
}

function marginalia_http_sequential(array $requests, array $results, ?float $budget = null): array
{
    $budget = marginalia_fetch_budget($budget);
    $left = marginalia_build_deadline_left();
    if ($left !== null && $left < $budget) {
        $budget = $left;
    }
    if ($budget <= 0) {
        foreach ($results as $key => $row) {
            $results[$key]['error'] = 'skipped: time budget used up';
        }
        return $results;
    }
    $perRequest = (float) marginalia_config('http.sequential_timeout');
    if ($perRequest <= 0) {
        $perRequest = 6.0;
    }

    $start = microtime(true);
    foreach ($requests as $key => $request) {
        $url = isset($request['url']) ? (string) $request['url'] : '';
        if ($url === '') {
            $results[$key]['error'] = 'empty url';
            continue;
        }

        $remaining = $budget - (microtime(true) - $start);
        if ($remaining < 2.0) {
            $results[$key]['error'] = 'skipped: time budget used up';
            continue;
        }

        $result = marginalia_http_single($url, min($perRequest, $remaining - 1.0));
        $result['url'] = $url;
        $result['batch_ms'] = (int) round((microtime(true) - $start) * 1000);
        $results[$key] = $result;
    }

    return $results;
}

function marginalia_fetch_budget(?float $desired = null): float
{
    if ($desired === null || $desired <= 0) {
        $desired = (float) marginalia_config('http.total_budget');
    }
    if ($desired <= 0) {
        $desired = 40.0;
    }

    // 给解析、写盘和页面渲染留出余量；主机限制较小时自动缩短抓取预算。
    $limit = (int) ini_get('max_execution_time');
    if ($limit > 0) {
        $available = $limit - 15.0;
        if ($available < 12.0) {
            $available = 12.0;
        }
        if ($available < $desired) {
            $desired = $available;
        }
    }

    return $desired;
}

function marginalia_http_multi(array $requests, ?float $budget = null): array
{
    $results = [];
    foreach ($requests as $key => $request) {
        $results[$key] = [
            'ok'      => false,
            'status'  => 0,
            'body'    => '',
            'error'   => 'not fetched',
            'url'     => isset($request['url']) ? (string) $request['url'] : '',
            'bytes'   => 0,
            'ms'      => 0,
        ];
    }

    if (!marginalia_curl_multi_available()) {
        return marginalia_http_sequential($requests, $results, $budget);
    }

    $multi = curl_multi_init();
    if (!$multi) {
        return marginalia_http_sequential($requests, $results, $budget);
    }
    $handles = [];
    $start = microtime(true);
    foreach ($requests as $key => $request) {
        $url = isset($request['url']) ? (string) $request['url'] : '';
        if ($url === '') {
            continue;
        }
        $ch = curl_init();
        $timeout = (float) ($request['timeout'] ?? marginalia_config('http.timeout'));
        $options = marginalia_curl_options($url, $timeout);
        if (!empty($request['headers']) && is_array($request['headers'])) {
            $options[CURLOPT_HTTPHEADER] = array_merge($options[CURLOPT_HTTPHEADER], $request['headers']);
        }
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($multi, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $bytes = is_string($body) ? strlen($body) : 0;
        $max = (int) marginalia_config('http.max_bytes');
        if ($max > 0 && $bytes > $max) {
            $error = 'body too large (' . $bytes . ' bytes)';
            $body = '';
            $bytes = 0;
        }
        $results[$key] = [
            'ok'     => $code >= 200 && $code < 400 && is_string($body) && $body !== '' && $error === '',
            'status' => $code,
            'body'   => is_string($body) ? $body : '',
            'error'  => $error,
            'url'    => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'bytes'  => $bytes,
            'ms'     => (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000),
            'batch_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return $results;
}

function marginalia_http_single_curl(string $url, float $timeout): array
{
    $start = microtime(true);
    $ch = curl_init();
    if (!$ch) {
        return [
            'ok'     => false,
            'status' => 0,
            'body'   => '',
            'error'  => 'curl init failed',
            'url'    => $url,
            'bytes'  => 0,
            'ms'     => 0,
        ];
    }

    $options = marginalia_curl_options($url, $timeout);
    $connect = (int) marginalia_config('http.connect_timeout');
    $options[CURLOPT_CONNECTTIMEOUT] = max(1, min(max(1, $connect), (int) ceil($timeout)));
    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $bytes = is_string($body) ? strlen($body) : 0;
    $max = (int) marginalia_config('http.max_bytes');
    if ($max > 0 && $bytes > $max) {
        $error = 'body too large (' . $bytes . ' bytes)';
        $body = '';
        $bytes = 0;
    }
    if (!is_string($body)) {
        $body = '';
        $bytes = 0;
    }

    return [
        'ok'     => $status >= 200 && $status < 400 && $body !== '' && $error === '',
        'status' => $status,
        'body'   => $body,
        'error'  => $error,
        'url'    => $effective !== '' ? $effective : $url,
        'bytes'  => $bytes,
        'ms'     => (int) round((microtime(true) - $start) * 1000),
    ];
}

function marginalia_http_single(string $url, float $timeout): array
{
    if (function_exists('curl_init') && function_exists('curl_exec')) {
        return marginalia_http_single_curl($url, $timeout);
    }

    $start = microtime(true);
    $ca = (string) marginalia_config('http.ca_bundle');
    $contextOptions = [
        'http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", marginalia_http_headers()),
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 1,
            'max_redirects'   => 4,
            'user_agent'      => (string) marginalia_config('http.user_agent'),
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];
    if ($ca !== '' && is_file($ca)) {
        $contextOptions['ssl']['cafile'] = $ca;
    }
    $context = stream_context_create($contextOptions);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/\s(\d{3})\s/', $header, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    $bytes = is_string($body) ? strlen($body) : 0;
    $max = (int) marginalia_config('http.max_bytes');
    $error = '';
    if ($body === false) {
        $error = 'stream request failed';
        $body = '';
        $bytes = 0;
    } elseif ($max > 0 && $bytes > $max) {
        $error = 'body too large';
        $body = '';
        $bytes = 0;
    }
    return [
        'ok'     => $status >= 200 && $status < 400 && is_string($body) && $body !== '' && $error === '',
        'status' => $status,
        'body'   => is_string($body) ? $body : '',
        'error'  => $error,
        'url'    => $url,
        'bytes'  => $bytes,
        'ms'     => (int) round((microtime(true) - $start) * 1000),
    ];
}

/* -------------------------------------------------------------------------
 * RSS / Atom 解析
 * ---------------------------------------------------------------------- */

function marginalia_xml_value(SimpleXMLElement $node, array $names): string
{
    foreach ($names as $name) {
        $nodes = $node->xpath('./*[local-name()="' . $name . '"]');
        if (is_array($nodes) && isset($nodes[0])) {
            $value = trim((string) $nodes[0]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function marginalia_xml_link(SimpleXMLElement $node): string
{
    $links = $node->xpath('./*[local-name()="link"]');
    if (is_array($links)) {
        foreach ($links as $link) {
            $href = trim((string) $link['href']);
            $rel = trim((string) $link['rel']);
            if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
                return $href;
            }
        }
        foreach ($links as $link) {
            $href = trim((string) $link['href']);
            if ($href !== '') {
                return $href;
            }
            $text = trim((string) $link);
            if ($text !== '') {
                return $text;
            }
        }
    }
    $guid = marginalia_xml_value($node, ['guid', 'id']);
    if (strpos($guid, 'http') === 0) {
        return $guid;
    }
    return '';
}

function marginalia_parse_feed(string $xml): array
{
    $xml = marginalia_fix_encoding($xml);
    if ($xml === '') {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($sx === false) {
        return marginalia_parse_feed_regex($xml);
    }

    $items = $sx->xpath('//*[local-name()="item"]');
    if (!is_array($items) || !$items) {
        $items = $sx->xpath('//*[local-name()="entry"]');
    }
    if (!is_array($items)) {
        return [];
    }

    $out = [];
    foreach ($items as $item) {
        if (!$item instanceof SimpleXMLElement) {
            continue;
        }
        $title = marginalia_xml_value($item, ['title']);
        $link = marginalia_xml_link($item);
        $description = marginalia_xml_value($item, ['description', 'summary', 'content', 'encoded']);
        $date = marginalia_xml_value($item, ['pubDate', 'published', 'updated', 'date']);
        $categories = [];
        $nodes = $item->xpath('./*[local-name()="category"]');
        if (is_array($nodes)) {
            foreach ($nodes as $category) {
                $value = trim((string) $category);
                if ($value !== '') {
                    $categories[] = $value;
                }
            }
        }
        if ($title === '' || $link === '') {
            continue;
        }
        $out[] = [
            'title'       => $title,
            'link'        => $link,
            'description' => $description,
            'date'        => $date,
            'categories'  => $categories,
        ];
    }
    return $out;
}

function marginalia_parse_feed_regex(string $xml): array
{
    $out = [];
    if (!preg_match_all('~<(item|entry)\b[^>]*>(.*?)</\1>~is', $xml, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $block = $match[2];
        $title = marginalia_regex_tag($block, ['title']);
        $link = '';
        if (preg_match('~<link\b[^>]*href=["\']([^"\']+)["\'][^>]*>~i', $block, $m)) {
            $link = $m[1];
        }
        if ($link === '') {
            $link = marginalia_regex_tag($block, ['link']);
        }
        $description = marginalia_regex_tag($block, ['description', 'summary', 'content:encoded', 'content', 'encoded']);
        $date = marginalia_regex_tag($block, ['pubDate', 'published', 'updated', 'dc:date', 'date']);
        if ($title === '' || $link === '') {
            continue;
        }
        $out[] = [
            'title'       => $title,
            'link'        => $link,
            'description' => $description,
            'date'        => $date,
            'categories'  => [],
        ];
    }
    return $out;
}

function marginalia_regex_tag(string $block, array $tags): string
{
    foreach ($tags as $tag) {
        $pattern = '~<' . preg_quote($tag, '~') . '\b[^>]*>(.*?)</' . preg_quote($tag, '~') . '>~is';
        if (preg_match($pattern, $block, $m)) {
            $value = trim($m[1]);
            $value = preg_replace('~^\s*<!\[CDATA\[(.*?)\]\]>\s*$~is', '$1', $value);
            return trim((string) $value);
        }
    }
    return '';
}

function marginalia_strip_source_suffix(string $title, string $source): string
{
    $title = trim($title);
    foreach ([' - ', ' — ', ' | ', '_', '·'] as $separator) {
        $suffix = $separator . $source;
        if (strlen($title) > strlen($suffix) + 8 && substr($title, -strlen($suffix)) === $suffix) {
            $title = trim(substr($title, 0, -strlen($suffix)));
        }
    }
    return trim($title);
}

/* -------------------------------------------------------------------------
 * 项目来源
 * ---------------------------------------------------------------------- */

function marginalia_projects_from_hn(string $json, string $date): array
{
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['hits']) || !is_array($data['hits'])) {
        return [];
    }
    $now = time();
    $maxAge = ((int) marginalia_config('filters.hn_max_age_days')) * 86400;
    $minPoints = (int) marginalia_config('filters.hn_min_points');
    $minComments = (int) marginalia_config('filters.hn_min_comments');
    $blocked = (array) marginalia_config('filters.project_blocked_words');
    $out = [];

    foreach ($data['hits'] as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $title = marginalia_clean_text($hit['title'] ?? '');
        if ($title === '') {
            continue;
        }
        if (stripos($title, 'Show HN:') === 0) {
            $title = trim(substr($title, 8));
        }
        $url = marginalia_normalize_url($hit['url'] ?? '');
        if ($url === '' || marginalia_is_blocked_url($url)) {
            continue;
        }
        $published = marginalia_parse_date($hit['created_at'] ?? '');
        if ($published <= 0 || $published < $now - $maxAge) {
            continue;
        }
        $points = (int) ($hit['points'] ?? 0);
        $comments = (int) ($hit['num_comments'] ?? 0);
        // 质量线：分数够高，或者讨论够多。两条都不满足的刚发帖不再进清单。
        if ($points < $minPoints && $comments < $minComments) {
            continue;
        }

        $description = '';
        if (preg_match('/\s+[–—]\s+|\s+-\s+/u', $title, $match, PREG_OFFSET_CAPTURE)) {
            $offset = (int) $match[0][1];
            $description = trim(substr($title, $offset + strlen($match[0][0])));
            $title = trim(substr($title, 0, $offset));
        }
        if ($title === '') {
            continue;
        }
        $description = marginalia_truncate(marginalia_clean_text($description), 220);
        $title = marginalia_truncate(marginalia_clean_text($title), 120);
        if (marginalia_has_word($title . ' ' . $description, $blocked)) {
            continue;
        }

        $source = marginalia_source_meta('hn');
        $tags = ['Show HN'];
        if (strpos($url, 'github.com/') !== false) {
            $tags[] = '开源';
        }
        $meta = $points . ' 分';
        if ($comments > 0) {
            $meta .= ' · ' . $comments . ' 评论';
        }
        $out[] = [
            'title'            => $title,
            'url'              => $url,
            'description'      => $description,
            'description_lang' => marginalia_desc_lang($description),
            'source_key'       => 'hn',
            'source'           => $source['label'],
            'badge'            => $source['badge'],
            'meta'             => $meta,
            'tags'             => $tags,
            'score'            => $points * 10 + $comments * 2 + max(0, 72 - (int) round(($now - $published) / 3600)),
            'published'        => $published,
            'discuss_url'      => 'https://news.ycombinator.com/item?id=' . rawurlencode((string) ($hit['objectID'] ?? '')),
        ];
    }

    usort($out, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($out, 0, 40);
}

function marginalia_projects_from_github(string $json, string $date): array
{
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
        return [];
    }
    $blocked = (array) marginalia_config('filters.github_blocked_words');
    $minStars = (int) marginalia_config('filters.github_min_stars');
    $out = [];

    foreach ($data['items'] as $repo) {
        if (!is_array($repo) || !empty($repo['fork']) || !empty($repo['archived'])) {
            continue;
        }
        $stars = (int) ($repo['stargazers_count'] ?? 0);
        if ($stars < $minStars) {
            continue;
        }
        $name = marginalia_clean_text($repo['name'] ?? '');
        $description = marginalia_clean_text($repo['description'] ?? '');
        $fullName = marginalia_clean_text($repo['full_name'] ?? '');
        if ($name === '' || $fullName === '' || strlen($description) < 10) {
            continue;
        }
        $topics = isset($repo['topics']) && is_array($repo['topics']) ? implode(' ', $repo['topics']) : '';
        if (marginalia_has_word($name . ' ' . $fullName . ' ' . $description . ' ' . $topics, $blocked)) {
            continue;
        }
        $url = marginalia_normalize_url($repo['html_url'] ?? '');
        if ($url === '' || marginalia_is_blocked_url($url)) {
            continue;
        }
        $language = marginalia_clean_text($repo['language'] ?? '');
        $source = marginalia_source_meta('github');
        $meta = '★ ' . marginalia_format_number($stars);
        if ($language !== '') {
            $meta .= ' · ' . marginalia_truncate($language, 18);
        }
        $tags = ['开源'];
        if ($language !== '') {
            $tags[] = $language;
        }
        $out[] = [
            'title'            => marginalia_truncate($name, 80),
            'url'              => $url,
            'description'      => marginalia_truncate($description, 220),
            'description_lang' => marginalia_desc_lang($description),
            'source_key'       => 'github',
            'source'           => $source['label'],
            'badge'            => $source['badge'],
            'meta'             => $meta,
            'tags'             => $tags,
            'score'            => $stars,
            'published'        => marginalia_parse_date($repo['created_at'] ?? ''),
            'discuss_url'      => '',
        ];
    }

    usort($out, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($out, 0, 40);
}

function marginalia_projects_from_github_trending(string $html, string $date): array
{
    if (!preg_match_all('~<article class="Box-row">(.*?)</article>~is', $html, $matches)) {
        return [];
    }
    $blocked = (array) marginalia_config('filters.github_blocked_words');
    $now = time();
    $out = [];

    foreach ($matches[1] as $block) {
        if (!preg_match('~<h2[^>]*>.*?<a[^>]+href="/([^"/]+)/([^"/]+)"~is', $block, $m)) {
            continue;
        }
        $owner = marginalia_clean_text($m[1]);
        $repo = marginalia_clean_text($m[2]);
        if ($owner === '' || $repo === '' || $owner === 'sponsors') {
            continue;
        }
        $description = '';
        if (preg_match('~<p[^>]+class="[^"]*col-9[^"]*"[^>]*>(.*?)</p>~is', $block, $descMatch)) {
            $description = marginalia_clean_text($descMatch[1]);
        }
        $language = '';
        if (preg_match('~itemprop="programmingLanguage"[^>]*>([^<]+)<~i', $block, $langMatch)) {
            $language = marginalia_clean_text($langMatch[1]);
        }
        $starsToday = 0;
        if (preg_match('~([\d,]+)\s+stars today~i', $block, $starMatch)) {
            $starsToday = (int) str_replace(',', '', $starMatch[1]);
        }
        if (marginalia_has_word($owner . ' ' . $repo . ' ' . $description, $blocked)) {
            continue;
        }
        $url = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo);
        $source = marginalia_source_meta('github');
        $meta = $starsToday > 0 ? '今日 +' . number_format($starsToday) . ' 星' : 'GitHub Trending';
        if ($language !== '') {
            $meta .= ' · ' . marginalia_truncate($language, 18);
        }
        $tags = ['开源', 'Trending'];
        if ($language !== '') {
            $tags[] = $language;
        }
        $out[] = [
            'title'            => marginalia_truncate($repo, 80),
            'url'              => $url,
            'description'      => marginalia_truncate($description, 220),
            'description_lang' => marginalia_desc_lang($description),
            'source_key'       => 'github',
            'source'           => $source['label'],
            'badge'            => $source['badge'],
            'meta'             => $meta,
            'tags'             => $tags,
            'score'            => 100000 + $starsToday,
            'published'        => $now,
            'discuss_url'      => '',
        ];
    }

    usort($out, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($out, 0, 30);
}

function marginalia_projects_from_ph(string $xml, string $date): array
{
    $items = marginalia_parse_feed($xml);
    $now = time();
    $maxAge = ((int) marginalia_config('filters.ph_max_age_days')) * 86400;
    $out = [];

    foreach ($items as $item) {
        $published = marginalia_parse_date($item['date'] ?? '');
        if ($published <= 0 || $published < $now - $maxAge) {
            continue;
        }
        $title = marginalia_truncate(marginalia_clean_text($item['title'] ?? ''), 90);
        $url = marginalia_normalize_url($item['link'] ?? '');
        if ($title === '' || $url === '' || marginalia_is_blocked_url($url)) {
            continue;
        }
        $description = marginalia_clean_text($item['description'] ?? '');
        $description = preg_replace('/\bDiscussion\b/i', ' ', $description);
        $description = preg_replace('/\bLink\b/i', ' ', $description);
        $description = trim(preg_replace('/\s*\|\s*/', ' ', $description));
        $description = trim($description, " \t\n\r\0\x0B|");
        $description = marginalia_truncate($description, 220);
        $source = marginalia_source_meta('ph');
        $out[] = [
            'title'            => $title,
            'url'              => $url,
            'description'      => $description,
            'description_lang' => marginalia_desc_lang($description),
            'source_key'       => 'ph',
            'source'           => $source['label'],
            'badge'            => $source['badge'],
            'meta'             => 'Product Hunt · ' . date('m.d', $published),
            'tags'             => ['产品', '发布'],
            'score'            => max(0, $maxAge - ($now - $published)),
            'published'        => $published,
            'discuss_url'      => '',
        ];
    }

    usort($out, function ($a, $b) {
        return $b['published'] <=> $a['published'];
    });
    return array_slice($out, 0, 30);
}

function marginalia_projects_from_awwwards(string $xml, string $date): array
{
    $items = marginalia_parse_feed($xml);
    $now = time();
    $maxAge = ((int) marginalia_config('filters.aww_max_age_days')) * 86400;
    $out = [];

    foreach ($items as $item) {
        $published = marginalia_parse_date($item['date'] ?? '');
        if ($published <= 0 || $published < $now - $maxAge) {
            continue;
        }
        $title = marginalia_truncate(marginalia_clean_text($item['title'] ?? ''), 90);
        $detailUrl = marginalia_normalize_url($item['link'] ?? '');
        if ($title === '' || $detailUrl === '') {
            continue;
        }
        $description = marginalia_clean_text($item['description'] ?? '');
        if (strpos($description, $title) === 0) {
            $description = trim(substr($description, strlen($title)));
        }
        $source = marginalia_source_meta('awwwards');
        $out[] = [
            'title'            => $title,
            'url'              => '', // 待解析成真实站点
            'detail_url'       => $detailUrl,
            'description'      => marginalia_truncate($description, 220),
            'description_lang' => marginalia_desc_lang($description),
            'source_key'       => 'awwwards',
            'source'           => $source['label'],
            'badge'            => $source['badge'],
            'meta'             => 'Awwwards · 每日站点',
            'tags'             => ['设计', '站点'],
            'score'            => max(0, $maxAge - ($now - $published)),
            'published'        => $published,
            'discuss_url'      => '',
        ];
    }

    usort($out, function ($a, $b) {
        return $b['published'] <=> $a['published'];
    });
    $out = array_slice($out, 0, 6);
    return marginalia_resolve_awwwards($out);
}

function marginalia_resolve_awwwards(array $entries): array
{
    if (!$entries) {
        return [];
    }

    $parallel = marginalia_curl_multi_available();

    // 顺序抓取时，详情页要一个个来。只试前两个，失败就退回 Awwwards 项目页，
    // 避免为了拿“官网地址”把整次构建拖到超时。
    $limit = $parallel ? 8 : 2;
    $entries = array_slice($entries, 0, $limit, true);

    $requests = [];
    foreach ($entries as $index => $entry) {
        if (!empty($entry['detail_url'])) {
            $requests['aw_' . $index] = [
                'url'     => $entry['detail_url'],
                'timeout' => $parallel ? 8 : 3,
                'headers' => ['Accept: text/html,application/xhtml+xml'],
            ];
        }
    }

    $responses = $requests ? marginalia_http_multi($requests) : [];
    $out = [];
    foreach ($entries as $index => $entry) {
        $response = $responses['aw_' . $index] ?? null;
        $resolved = '';
        if ($response && !empty($response['ok'])) {
            $html = $response['body'];
            $external = '';
            if (preg_match('~<a[^>]+class="[^"]*toolbar-bts__item[^"]*"[^>]+href="(https?://[^"]+)"~i', $html, $m)) {
                $external = $m[1];
            } elseif (preg_match('~<a[^>]+href="(https?://[^"]+)"[^>]+class="[^"]*toolbar-bts__item[^"]*"~i', $html, $m)) {
                $external = $m[1];
            }
            $external = marginalia_normalize_url($external);
            if ($external !== '' && !marginalia_is_blocked_url($external)) {
                $host = strtolower((string) parse_url($external, PHP_URL_HOST));
                if (strpos($host, 'awwwards.com') === false) {
                    $resolved = $external;
                }
            }
        }

        if ($resolved !== '') {
            $entry['url'] = $resolved;
            unset($entry['detail_url']);
            $out[] = $entry;
            continue;
        }

        if (!empty($entry['detail_url'])) {
            $entry['url'] = $entry['detail_url'];
            unset($entry['detail_url']);
            $out[] = $entry;
        }
    }
    return $out;
}

function marginalia_finalize_project(array $project, int $index): array
{
    $source = marginalia_source_meta((string) ($project['source_key'] ?? 'seed'));
    $project['title'] = marginalia_truncate(marginalia_clean_text($project['title'] ?? ''), 120);
    $project['description'] = marginalia_truncate(marginalia_clean_text($project['description'] ?? ''), 220);
    $project['description_lang'] = $project['description_lang'] ?? marginalia_desc_lang($project['description']);
    $project['source'] = $project['source'] ?? $source['label'];
    $project['badge'] = $project['badge'] ?? $source['badge'];
    $project['meta'] = $project['meta'] ?? '';
    $project['tags'] = array_slice(array_values(array_filter((array) ($project['tags'] ?? []))), 0, 3);
    $project['domain'] = marginalia_domain_label($project['url'] ?? '');
    $project['glyph'] = marginalia_glyph((int) ($project['glyph'] ?? $index));
    $project['no'] = $index;
    $category = (string) ($project['category'] ?? '');
    $categoryDefs = marginalia_category_defs();
    $project['category'] = isset($categoryDefs[$category]) ? $category : marginalia_project_category($project);
    return $project;
}

/* -------------------------------------------------------------------------
 * 项目归类：用 / 造 / 看 / 玩
 *
 * 只用来源先验和标题、描述里的关键词打分，不调用外部服务、不增加构建耗时。
 * 分类是近似的，页面上也照实说明；宁可偶尔放错抽屉，也不要为了让每格一样大而硬塞。
 * ---------------------------------------------------------------------- */

function marginalia_category_defs(): array
{
    return [
        'use' => [
            'hanzi' => '用',
            'name'  => '工具与产品',
            'hint'  => '打开就能用：产品、服务、效率工具。',
        ],
        'build' => [
            'hanzi' => '造',
            'name'  => '开发与基建',
            'hint'  => '给做东西的人：库、框架、命令行、基础设施。',
        ],
        'see' => [
            'hanzi' => '看',
            'name'  => '设计与视觉',
            'hint'  => '关于眼睛：字体、排版、网站、界面。',
        ],
        'play' => [
            'hanzi' => '玩',
            'name'  => '游戏与实验',
            'hint'  => '用来玩的：游戏、玩具、奇想、硬件。',
        ],
    ];
}

function marginalia_category_keywords(): array
{
    return [
        'use' => [
            'app' => 1.2, 'tool' => 1.4, 'assistant' => 1.4, 'agent' => 1.2,
            'automation' => 1.2, 'workflow' => 1.0, 'productivity' => 1.4,
            'dashboard' => 1.2, 'saas' => 1.2, 'platform' => 1.0, 'service' => 0.8,
            'email' => 1.0, 'notes' => 1.0, 'calendar' => 1.0, 'analytics' => 1.2,
            'marketing' => 1.2, 'crm' => 1.2, 'invoice' => 1.2, 'newsletter' => 1.0,
            '工具' => 1.4, '产品' => 1.2, '助手' => 1.4, '自动化' => 1.2, 'studio' => 1.2, 'clone' => 0.8,
            '效率' => 1.2, '平台' => 0.8, '应用' => 1.0, '营销' => 1.2, '笔记' => 1.0,
        ],
        'build' => [
            'api' => 1.2, 'sdk' => 1.4, 'library' => 1.4, 'framework' => 1.4,
            'cli' => 1.4, 'compiler' => 1.4, 'runtime' => 1.2, 'database' => 1.2,
            'docker' => 1.2, 'kubernetes' => 1.2, 'server' => 1.0, 'self-hosted' => 1.2,
            'open-source' => 0.3, 'open source' => 0.3, 'terminal' => 0.6, 'editor' => 1.0,
            'ide' => 1.2, 'linter' => 1.2, 'parser' => 1.2, 'protocol' => 1.2,
            'infrastructure' => 1.2, 'devtool' => 1.4, 'debug' => 1.0, 'deploy' => 1.0,
            'hosting' => 1.2, 'proxy' => 1.0, 'cache' => 1.0, 'typescript' => 0.8,
            'rust' => 0.8, 'golang' => 0.8, 'python' => 0.8,
            '开源' => 1.0, '框架' => 1.4, '编译器' => 1.4, '命令行' => 1.4,
            '编辑器' => 1.2, '数据库' => 1.2, '服务器' => 1.0, '部署' => 1.0, '协议' => 1.0,
        ],
        'see' => [
            'font' => 1.6, 'typeface' => 1.6, 'typography' => 1.6, 'design' => 1.4,
            'ui' => 1.2, 'ux' => 1.2, 'css' => 1.4, 'animation' => 1.2, 'webgl' => 1.4,
            'three.js' => 1.4, 'portfolio' => 1.4, 'gallery' => 1.2, 'visual' => 1.2,
            'illustration' => 1.4, 'poster' => 1.2, 'branding' => 1.2, 'logo' => 1.2,
            'color' => 1.0, 'icon' => 1.2, 'layout' => 1.2, 'motion' => 1.2, 'award' => 1.2,
            '字体' => 1.6, '设计' => 1.4, '排版' => 1.6, '视觉' => 1.2, '动画' => 1.2,
            '界面' => 1.2, '图标' => 1.2, '配色' => 1.2, '作品集' => 1.4,
        ],
        'play' => [
            'game' => 1.6, 'gaming' => 1.6, 'arcade' => 1.6, 'roguelike' => 1.6,
            'pixel' => 1.2, 'toy' => 1.4, 'simulator' => 1.2, 'honeypot' => 1.4,
            'esp8266' => 1.4, 'arduino' => 1.4, 'raspberry' => 1.4, 'joke' => 1.4,
            'funny' => 1.4, 'experiment' => 1.2, 'drift' => 1.2, 'racing' => 1.2,
            '游戏' => 1.6, '玩具' => 1.4, '实验' => 1.2, '硬件' => 1.2, '好玩' => 1.4, 'survival' => 1.4, 'multiplayer' => 0.8,
        ],
    ];
}

function marginalia_keyword_hit(string $text, string $word): bool
{
    if ($word === '' || $text === '') {
        return false;
    }
    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $word)) {
        return strpos($text, $word) !== false;
    }
    return (bool) preg_match('/\b' . preg_quote($word, '/') . '/u', $text);
}

function marginalia_project_category(array $project): string
{
    $defs = marginalia_category_defs();
    $title = (string) ($project['title'] ?? '');
    $desc = (string) ($project['description'] ?? '');
    $url = strtolower((string) ($project['url'] ?? ''));
    $sourceKey = (string) ($project['source_key'] ?? '');

    $text = $title . ' ' . $desc;
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    // 来源本身就是最稳的信号：Awwwards 是视觉站，Product Hunt 是对外产品，
    // GitHub 是代码仓库。只有 HN 与常青拾遗混合度太高，才需要按关键词再分。
    if ($sourceKey === 'awwwards') {
        return 'see';
    }
    if ($sourceKey === 'ph') {
        return 'use';
    }
    if ($sourceKey === 'github') {
        return 'build';
    }

    $scores = array_fill_keys(array_keys($defs), 0.0);
    if (strpos($url, 'github.com/') !== false) {
        $scores['build'] += 0.6;
    }

    foreach (marginalia_category_keywords() as $cat => $words) {
        foreach ($words as $word => $weight) {
            if (marginalia_keyword_hit($text, (string) $word)) {
                $scores[$cat] += (float) $weight;
            }
        }
    }

    $best = '';
    $bestScore = 0.0;
    foreach ($scores as $cat => $score) {
        if ($score > $bestScore) {
            $best = $cat;
            $bestScore = $score;
        }
    }

    if ($best !== '') {
        return $best;
    }

    // 一个关键词都没命中时，按来源兜底。
    if ($sourceKey === 'awwwards') {
        return 'see';
    }
    if ($sourceKey === 'github') {
        return 'build';
    }
    return 'use';
}

/* -------------------------------------------------------------------------
 * 新闻来源
 * ---------------------------------------------------------------------- */

function marginalia_news_from_feed(string $xml, string $key, array $source, string $issueDate): array
{
    $items = marginalia_parse_feed($xml);
    $now = time();
    $maxAge = ((int) marginalia_config('filters.news_max_age_hours')) * 3600;
    $blocked = (array) marginalia_config('filters.news_blocked_words');
    $soft = (array) marginalia_config('filters.news_soft_words');
    $label = (string) ($source['label'] ?? $key);
    $out = [];

    foreach ($items as $item) {
        $title = marginalia_clean_text($item['title'] ?? '');
        $url = marginalia_normalize_url($item['link'] ?? '');
        if ($title === '' || $url === '') {
            continue;
        }
        $published = marginalia_parse_date($item['date'] ?? '');
        if ($published <= 0 || $published < $now - $maxAge) {
            continue;
        }
        $title = marginalia_strip_source_suffix($title, $label);
        $title = marginalia_truncate($title, 180);
        if (marginalia_strlen($title) < 6) {
            continue;
        }
        if (marginalia_has_word($title, $blocked)) {
            continue;
        }
        if ($soft && marginalia_has_word($title, $soft)) {
            continue;
        }
        $out[] = [
            'title'      => $title,
            'url'        => $url,
            'source'     => $label,
            'source_key' => $key,
            'lang'       => (string) ($source['lang'] ?? 'zh'),
            'published'  => $published,
            'time_label' => marginalia_news_time_label($published, $issueDate),
        ];
    }

    usort($out, function ($a, $b) {
        return $b['published'] <=> $a['published'];
    });
    return $out;
}

function marginalia_news_time_label(int $timestamp, string $issueDate): string
{
    if ($timestamp <= 0) {
        return '';
    }
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(marginalia_tz());
    if ($date->format('Y-m-d') === $issueDate) {
        return $date->format('H:i');
    }
    return $date->format('m.d H:i');
}

function marginalia_news_key(string $title): string
{
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $title = preg_replace('/[\s\p{P}\p{S}]+/u', '', $title);
    return is_string($title) ? $title : '';
}

function marginalia_select_news(array $buckets, int $count, array $primaryOrder, array $fallbackOrder = [], int $perSourceCap = 0): array
{
    $selected = [];
    $seen = [];
    $queues = [];
    $cursor = [];
    $used = [];
    $cap = $perSourceCap > 0 ? $perSourceCap : $count;

    foreach (array_unique(array_merge($primaryOrder, $fallbackOrder)) as $key) {
        $queues[$key] = isset($buckets[$key]) ? array_values($buckets[$key]) : [];
        $cursor[$key] = 0;
    }

    $takeOne = function (string $key, bool $respectCap) use (&$queues, &$cursor, &$selected, &$seen, &$used, $count, $cap): bool {
        if (count($selected) >= $count) {
            return false;
        }
        while (isset($queues[$key][$cursor[$key]])) {
            $item = $queues[$key][$cursor[$key]];
            $cursor[$key]++;
            $newsKey = marginalia_news_key((string) ($item['title'] ?? ''));
            if ($newsKey === '' || isset($seen[$newsKey])) {
                continue;
            }
            $label = (string) ($item['source'] ?? $key);
            if ($respectCap && $label !== '' && ($used[$label] ?? 0) >= $cap) {
                // 这一轮该来源已到上限；把条目留在队首，等溢出轮再来取。
                $cursor[$key]--;
                return false;
            }
            $seen[$newsKey] = true;
            if ($label !== '') {
                $used[$label] = ($used[$label] ?? 0) + 1;
            }
            $selected[] = $item;
            return true;
        }
        return false;
    };

    $fill = function (array $order, bool $respectCap) use (&$selected, $count, $takeOne): void {
        $progress = true;
        while (count($selected) < $count && $progress) {
            $progress = false;
            foreach ($order as $key) {
                if (count($selected) >= $count) {
                    return;
                }
                if ($takeOne($key, $respectCap)) {
                    $progress = true;
                }
            }
        }
    };

    // 第一轮：每个来源最多 $cap 条，让版面分散到多家。
    $fill($primaryOrder, true);

    // 第二轮：中文源不够时，先用英文补位，同样守配额。
    if (count($selected) < $count && $fallbackOrder) {
        $fill($fallbackOrder, true);
    }

    // 第三轮：确实凑不齐，才放宽配额。
    if (count($selected) < $count) {
        $fill($primaryOrder, false);
    }
    if (count($selected) < $count && $fallbackOrder) {
        $fill($fallbackOrder, false);
    }

    usort($selected, function ($a, $b) {
        return $b['published'] <=> $a['published'];
    });
    return $selected;
}

/* -------------------------------------------------------------------------
 * 常青拾遗与选择
 * ---------------------------------------------------------------------- */

function marginalia_seed_projects(string $date, int $count, array $excludeKeys = []): array
{
    $seed = require __DIR__ . '/data/seed.php';
    $items = isset($seed['projects']) && is_array($seed['projects']) ? $seed['projects'] : [];
    $exclude = array_fill_keys($excludeKeys, true);

    usort($items, function ($a, $b) use ($date) {
        $ha = hexdec(hash('crc32b', $date . '|' . (string) $a['url']));
        $hb = hexdec(hash('crc32b', $date . '|' . (string) $b['url']));
        if ($ha === $hb) {
            return strcmp((string) $a['title'], (string) $b['title']);
        }
        return $ha <=> $hb;
    });

    $out = [];
    foreach ($items as $index => $item) {
        $url = marginalia_normalize_url($item['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $key = marginalia_url_key($url);
        if ($key === '' || isset($exclude[$key])) {
            continue;
        }
        $source = marginalia_source_meta('seed');
        $out[] = [
            'title'            => $item['title'],
            'url'              => $url,
            'description'      => $item['description'] ?? '',
            'description_lang' => 'zh',
            'source_key'       => 'seed',
            'source'           => $source['label'],
            'badge'            => $source['badge'],
            'meta'             => $item['meta'] ?? '长期可读',
            'tags'             => $item['tags'] ?? [],
            'score'            => 0,
            'published'        => 0,
            'discuss_url'      => '',
            'glyph'            => 5 + ($index % 4),
        ];
        if (count($out) >= $count) {
            break;
        }
    }
    return $out;
}

function marginalia_select_projects(array $buckets, string $date): array
{
    $target = (int) marginalia_config('counts.projects');
    $quotas = (array) marginalia_config('quotas');
    $order = ['hn', 'github', 'ph', 'awwwards'];
    $selected = [];
    $usedUrlKeys = [];
    $diversity = [];
    $sourceCounts = [];

    $canAdd = function (array $project) use (&$usedUrlKeys, &$diversity): bool {
        $urlKey = marginalia_url_key((string) ($project['url'] ?? ''));
        if ($urlKey === '' || isset($usedUrlKeys[$urlKey])) {
            return false;
        }
        $sourceKey = (string) ($project['source_key'] ?? '');
        $divKey = marginalia_diversity_key((string) ($project['url'] ?? ''));
        $limit = $sourceKey === 'github' ? 1 : 2;
        if ($divKey !== '' && isset($diversity[$divKey]) && $diversity[$divKey] >= $limit) {
            return false;
        }
        return true;
    };

    $add = function (array $project) use (&$selected, &$usedUrlKeys, &$diversity, &$sourceCounts): bool {
        $urlKey = marginalia_url_key((string) ($project['url'] ?? ''));
        if ($urlKey === '') {
            return false;
        }
        $sourceKey = (string) ($project['source_key'] ?? 'seed');
        $divKey = marginalia_diversity_key((string) ($project['url'] ?? ''));
        $usedUrlKeys[$urlKey] = true;
        if ($divKey !== '') {
            $diversity[$divKey] = ($diversity[$divKey] ?? 0) + 1;
        }
        $sourceCounts[$sourceKey] = ($sourceCounts[$sourceKey] ?? 0) + 1;
        $selected[] = $project;
        return true;
    };

    // 第一轮：按目标配额取，保证来源多样。
    foreach ($order as $sourceKey) {
        $targetForSource = isset($quotas[$sourceKey]['target']) ? (int) $quotas[$sourceKey]['target'] : 0;
        $bucket = isset($buckets[$sourceKey]) ? $buckets[$sourceKey] : [];
        $taken = 0;
        foreach ($bucket as $project) {
            if ($taken >= $targetForSource || count($selected) >= $target) {
                break;
            }
            if (!$canAdd($project)) {
                continue;
            }
            if ($add($project)) {
                $taken++;
            }
        }
    }

    // 第二轮：某个来源不够时，从其他来源补到上限。
    $progress = true;
    while (count($selected) < $target && $progress) {
        $progress = false;
        foreach ($order as $sourceKey) {
            if (count($selected) >= $target) {
                break;
            }
            $max = isset($quotas[$sourceKey]['max']) ? (int) $quotas[$sourceKey]['max'] : 0;
            $current = $sourceCounts[$sourceKey] ?? 0;
            if ($current >= $max) {
                continue;
            }
            $bucket = isset($buckets[$sourceKey]) ? $buckets[$sourceKey] : [];
            foreach ($bucket as $index => $project) {
                $urlKey = marginalia_url_key((string) ($project['url'] ?? ''));
                if ($urlKey !== '' && isset($usedUrlKeys[$urlKey])) {
                    continue;
                }
                if (!$canAdd($project)) {
                    continue;
                }
                if ($add($project)) {
                    $progress = true;
                    unset($buckets[$sourceKey][$index]);
                    break;
                }
            }
        }
    }

    // 第三轮：常青拾遗兜底。
    if (count($selected) < $target) {
        $seed = marginalia_seed_projects($date, $target, array_keys($usedUrlKeys));
        foreach ($seed as $project) {
            if (count($selected) >= $target) {
                break;
            }
            if ($canAdd($project)) {
                $add($project);
            }
        }
    }

    if (!$selected) {
        return [];
    }

    // 选一张“本期首选”：优先 HN 最高分，其次 GitHub 星标最多，再其次 PH / Awwwards。
    $leadIndex = 0;
    $leadSource = '';
    $leadScore = -1;
    foreach ($selected as $index => $project) {
        $sourceKey = (string) ($project['source_key'] ?? '');
        $score = (int) ($project['score'] ?? 0);
        $priority = ['hn' => 4, 'github' => 3, 'ph' => 2, 'awwwards' => 1, 'seed' => 0];
        $weight = ($priority[$sourceKey] ?? 0) * 1000000000 + $score;
        if ($weight > $leadScore) {
            $leadScore = $weight;
            $leadIndex = $index;
            $leadSource = $sourceKey;
        }
    }

    $lead = $selected[$leadIndex];
    unset($selected[$leadIndex]);
    $selected = array_values($selected);

    // 其余按来源轮转，避免同源扎堆。
    $grouped = [];
    foreach ($selected as $project) {
        $sourceKey = (string) ($project['source_key'] ?? 'seed');
        $grouped[$sourceKey][] = $project;
    }
    $rotation = ['github', 'ph', 'awwwards', 'hn', 'seed'];
    $start = array_search($leadSource, $rotation, true);
    if ($start === false) {
        $start = 0;
    }
    $rotation = array_merge(array_slice($rotation, $start + 1), array_slice($rotation, 0, $start + 1));

    $ordered = [$lead];
    $progress = true;
    while (count($ordered) < $target && $progress) {
        $progress = false;
        foreach ($rotation as $sourceKey) {
            if (empty($grouped[$sourceKey])) {
                continue;
            }
            $ordered[] = array_shift($grouped[$sourceKey]);
            $progress = true;
            if (count($ordered) >= $target) {
                break;
            }
        }
    }

    $final = [];
    foreach (array_slice($ordered, 0, $target) as $index => $project) {
        $final[] = marginalia_finalize_project($project, $index + 1);
    }
    return $final;
}

function marginalia_make_fallback_issue(string $date): array
{
    $projects = marginalia_seed_projects($date, (int) marginalia_config('counts.projects'));
    $final = [];
    foreach ($projects as $index => $project) {
        $final[] = marginalia_finalize_project($project, $index + 1);
    }
    return [
        'date'          => $date,
        'issue'         => marginalia_issue_number($date),
        'weekday'       => marginalia_weekday($date),
        'theme'         => marginalia_theme($date),
        'generated_at'  => marginalia_iso_now(),
        'updated_at'    => marginalia_iso_now(),
        'degraded'      => true,
        'counts'        => [
            'projects'  => count($final),
            'news_cn'   => 0,
            'news_intl' => 0,
        ],
        'projects'      => $final,
        'news_cn'       => [],
        'news_intl'     => [],
        'sources'       => [],
        'errors'        => ['今天的实时来源还没有连上；先展示常青拾遗。'],
    ];
}

/* -------------------------------------------------------------------------
 * 构建一期
 * ---------------------------------------------------------------------- */

function marginalia_build_issue(string $date, bool $force = false)
{
    marginalia_setup();
    @set_time_limit(55);
    $fetchBudget = marginalia_fetch_budget();
    marginalia_build_deadline_set($fetchBudget);

    $cacheDir = marginalia_cache_dir();
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        marginalia_log('cache directory is not writable: ' . $cacheDir);
        return null;
    }

    $lockPath = $cacheDir . '/build.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) {
        marginalia_log('cannot open build lock: ' . $lockPath);
        return null;
    }

    $locked = false;
    for ($attempt = 0; $attempt < 16; $attempt++) {
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            $locked = true;
            break;
        }
        usleep(500000);
        if (!$force && is_file(marginalia_issue_path($date))) {
            $existing = marginalia_load_issue($date);
            if ($existing) {
                fclose($lock);
                return $existing;
            }
        }
    }
    if (!$locked) {
        fclose($lock);
        marginalia_log('build lock timeout for ' . $date);
        return marginalia_load_issue($date);
    }

    if (!$force) {
        $existing = marginalia_load_issue($date);
        if ($existing) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return $existing;
        }
    }

    $started = microtime(true);
    $errors = [];
    $health = [];

    // 期号锚点：第一次成功构建时把当天设为第 001 期。
    $meta = marginalia_meta();
    if (empty($meta['epoch'])) {
        $meta['epoch'] = $date;
        $meta['created_at'] = marginalia_iso_now();
        marginalia_meta_save($meta);
    }

    $projectSources = (array) marginalia_config('sources.projects');
    $newsSources = [
        'news_cn'   => (array) marginalia_config('sources.news_cn'),
        'news_intl' => (array) marginalia_config('sources.news_intl'),
    ];

    $requests = [];
    $dateObj = marginalia_date_obj($date);
    $since = $dateObj->modify('-14 days')->format('Y-m-d');
    foreach ($projectSources as $key => $source) {
        if (empty($source['enabled'])) {
            continue;
        }
        $url = (string) $source['url'];
        if (($source['type'] ?? '') === 'github') {
            $url = str_replace('{since}', $since, $url);
        }
        $requests['p_' . $key] = ['url' => $url, 'timeout' => (float) marginalia_config('http.timeout')];
    }
    foreach ($newsSources as $group => $sources) {
        foreach ($sources as $key => $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            $requests['n_' . $key] = ['url' => (string) $source['url'], 'timeout' => (float) marginalia_config('http.timeout')];
        }
    }

    $responses = marginalia_http_multi($requests, $fetchBudget);

    $projectBuckets = [];
    foreach ($projectSources as $key => $source) {
        if (empty($source['enabled'])) {
            continue;
        }
        $response = $responses['p_' . $key] ?? null;
        $type = (string) ($source['type'] ?? '');
        $items = [];
        if ($response && $response['ok']) {
            if ($type === 'hn') {
                $items = marginalia_projects_from_hn($response['body'], $date);
            } elseif ($type === 'github') {
                $items = marginalia_projects_from_github($response['body'], $date);
            } elseif ($type === 'github_trending') {
                $items = marginalia_projects_from_github_trending($response['body'], $date);
            } elseif ($type === 'ph') {
                $items = marginalia_projects_from_ph($response['body'], $date);
            } elseif ($type === 'awwwards') {
                $items = marginalia_projects_from_awwwards($response['body'], $date);
            }
        }
        $bucketKey = $type === 'github_trending' || $type === 'github' ? 'github' : $key;
        if (!isset($projectBuckets[$bucketKey])) {
            $projectBuckets[$bucketKey] = [];
        }
        $projectBuckets[$bucketKey] = array_merge($projectBuckets[$bucketKey], $items);
        $health['project_' . $key] = [
            'label'   => (string) ($source['label'] ?? $key),
            'group'   => 'project',
            'ok'      => (bool) ($response['ok'] ?? false),
            'status'  => (int) ($response['status'] ?? 0),
            'items'   => count($items),
            'bytes'   => (int) ($response['bytes'] ?? 0),
            'ms'      => (int) ($response['ms'] ?? 0),
            'error'   => (string) ($response['error'] ?? ''),
        ];
        if (!$response || !$response['ok']) {
            $errors[] = '项目来源 ' . ($source['label'] ?? $key) . ' 抓取失败';
        } elseif (!$items) {
            $errors[] = '项目来源 ' . ($source['label'] ?? $key) . ' 没有可用条目';
        }
    }

    $newsBuckets = ['news_cn' => [], 'news_intl' => []];
    foreach ($newsSources as $group => $sources) {
        foreach ($sources as $key => $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            $response = $responses['n_' . $key] ?? null;
            $items = [];
            if ($response && $response['ok']) {
                $items = marginalia_news_from_feed($response['body'], $key, $source, $date);
            }
            $newsBuckets[$group][$key] = $items;
            $health['news_' . $key] = [
                'label'   => (string) ($source['label'] ?? $key),
                'group'   => $group,
                'ok'      => (bool) ($response['ok'] ?? false),
                'status'  => (int) ($response['status'] ?? 0),
                'items'   => count($items),
                'bytes'   => (int) ($response['bytes'] ?? 0),
                'ms'      => (int) ($response['ms'] ?? 0),
                'error'   => (string) ($response['error'] ?? ''),
            ];
            if (!$response || !$response['ok']) {
                $errors[] = '新闻来源 ' . ($source['label'] ?? $key) . ' 抓取失败';
            }
        }
    }

    $projects = marginalia_select_projects($projectBuckets, $date);
    $newsCnOrder = ['chinanews_china', 'ifanr', 'chinanews_scroll'];
    $newsIntlPrimary = ['chinanews_world', 'un_news_zh'];
    $newsIntlFallback = ['cgtn_world', 'npr_world'];
    $newsCap = (int) marginalia_config('filters.news_per_source_cap');
    $newsCn = marginalia_select_news($newsBuckets['news_cn'], (int) marginalia_config('counts.news_cn'), $newsCnOrder, [], $newsCap);
    $newsIntl = marginalia_select_news($newsBuckets['news_intl'], (int) marginalia_config('counts.news_intl'), $newsIntlPrimary, $newsIntlFallback, $newsCap);

    if (!$projects) {
        $projects = marginalia_make_fallback_issue($date)['projects'];
    }

    $counts = [
        'projects'  => count($projects),
        'news_cn'   => count($newsCn),
        'news_intl' => count($newsIntl),
    ];
    $degraded = $counts['projects'] < (int) marginalia_config('counts.projects')
        || $counts['news_cn'] < (int) marginalia_config('counts.news_cn')
        || $counts['news_intl'] < (int) marginalia_config('counts.news_intl');

    $issue = [
        'date'         => $date,
        'issue'        => marginalia_issue_number($date),
        'weekday'      => marginalia_weekday($date),
        'theme'        => marginalia_theme($date),
        'generated_at' => marginalia_iso_now(),
        'updated_at'   => marginalia_iso_now(),
        'degraded'     => $degraded,
        'counts'       => $counts,
        'projects'     => $projects,
        'news_cn'      => $newsCn,
        'news_intl'    => $newsIntl,
        'sources'      => $health,
        'errors'       => array_values(array_unique($errors)),
        'build_ms'     => (int) round((microtime(true) - $started) * 1000),
    ];

    $json = json_encode($issue, marginalia_json_flags());    if ($json === false) {
        marginalia_log('json_encode failed: ' . json_last_error_msg());
        $json = json_encode($issue, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    if (!marginalia_atomic_write(marginalia_issue_path($date), (string) $json)) {
        marginalia_log('cannot write issue file: ' . marginalia_issue_path($date));
        flock($lock, LOCK_UN);
        fclose($lock);
        return $issue;
    }

    @unlink($cacheDir . '/fail-' . $date . '.json');
    marginalia_prune_issues();

    if ($degraded) {
        marginalia_log('issue ' . $date . ' built with missing sections: ' . json_encode($counts));
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $issue;
}

function marginalia_ensure_issue($date = null, bool $force = false)
{
    marginalia_setup();
    if ($date === null) {
        $date = marginalia_current_issue_date();
    }
    $date = (string) $date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    $existing = marginalia_load_issue($date);
    if ($existing && !$force) {
        return $existing;
    }

    $failFile = marginalia_cache_dir() . '/fail-' . $date . '.json';
    if (!$force && is_file($failFile)) {
        $fail = marginalia_read_json($failFile);
        $at = isset($fail['at']) ? (int) $fail['at'] : 0;
        if ($at > 0 && time() - $at < (int) marginalia_config('cache.fail_cooldown')) {
            return $existing;
        }
    }

    $issue = marginalia_build_issue($date, $force);
    if ($issue) {
        @unlink($failFile);
        return $issue;
    }

    @marginalia_atomic_write($failFile, json_encode(['at' => time(), 'error' => 'build failed'], marginalia_json_flags()));
    return $existing;
}

/* -------------------------------------------------------------------------
 * 健康检查与 JSON 输出
 * ---------------------------------------------------------------------- */

function marginalia_request_key_ok(): bool
{
    $key = (string) marginalia_config('refresh.key');
    if ($key === '') {
        return true;
    }
    $provided = isset($_GET['key']) ? (string) $_GET['key'] : (isset($_POST['key']) ? (string) $_POST['key'] : '');
    return $provided !== '' && hash_equals($key, $provided);
}

function marginalia_health_data(bool $detailed = false): array
{
    $cache = marginalia_cache_dir();
    $writable = false;
    $probe = $cache . '/.write-test-' . getmypid();
    if (@file_put_contents($probe, 'ok') !== false) {
        $writable = true;
        @unlink($probe);
    }

    $latest = marginalia_latest_issue();
    $issueDate = marginalia_current_issue_date();
    $latestDate = marginalia_latest_issue_date();
    $failFile = $cache . '/fail-' . $issueDate . '.json';
    $fail = marginalia_read_json($failFile);

    $data = [
        'ok'             => $writable && function_exists('curl_init') && extension_loaded('simplexml'),
        'site'           => (string) marginalia_config('site.url'),
        'timezone'       => (string) marginalia_config('site.timezone'),
        'now'            => marginalia_iso_now(),
        'issue_date'     => $issueDate,
        'latest_issue'   => $latestDate,
        'next_drop'      => marginalia_next_drop_ts(),
        'next_drop_iso'  => (new DateTimeImmutable('@' . marginalia_next_drop_ts()))->setTimezone(marginalia_tz())->format(DateTime::ATOM),
        'cache_dir'      => $cache,
        'cache_writable' => $writable,
        'ca_bundle'      => is_file((string) marginalia_config('http.ca_bundle')),
        'fetch_mode'     => marginalia_curl_multi_available() ? 'parallel' : 'sequential',
        'last_failure'   => $fail,
        'php'            => [
            'version'             => PHP_VERSION,
            'sapi'                => PHP_SAPI,
            'curl'                => function_exists('curl_init'),
            'curl_version'        => function_exists('curl_version') ? (curl_version()['version'] ?? '') : '',
            'simplexml'           => extension_loaded('simplexml'),
            'mbstring'            => extension_loaded('mbstring'),
            'openssl'             => extension_loaded('openssl'),
            'allow_url_fopen'     => (bool) ini_get('allow_url_fopen'),
            'max_execution_time'  => (string) ini_get('max_execution_time'),
            'memory_limit'        => (string) ini_get('memory_limit'),
        ],
        'counts'         => $latest['counts'] ?? null,
        'degraded'       => $latest['degraded'] ?? null,
        'generated_at'   => $latest['generated_at'] ?? null,
        'build_ms'       => $latest['build_ms'] ?? null,
        'errors'         => [],
    ];

    if ($latest && !empty($latest['errors']) && is_array($latest['errors'])) {
        $data['errors'] = array_slice($latest['errors'], 0, 12);
    }

    if ($detailed) {
        $data['sources'] = $latest['sources'] ?? [];
        $log = $cache . '/error.log';
        if (is_file($log)) {
            $lines = @file($log, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                $data['log_tail'] = array_slice($lines, -25);
            }
        }
    }

    return $data;
}

function marginalia_json_response(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, marginalia_json_flags());
    exit;
}
