<?php
/**
 * 把 cache/ 里的每一期导出成静态文件。
 *
 * 用法：
 *   MARGINALIA_STATIC=1 MARGINALIA_SITE_URL=https://voidcode-47.github.io \
 *   MARGINALIA_BASE_PATH=/yubai php bin/export-static.php dist
 *
 * 产出：
 *   dist/index.html                    最新一期
 *   dist/issue/<日期>/index.html       每期固定地址
 *   dist/feed.xml                      RSS
 *   dist/assets/…                      样式、脚本、字体、分享图
 *
 * 页面本身仍由 deploy/index.php 渲染，所以静态站和 PHP 站的结构完全一致。
 */

require_once __DIR__ . '/../deploy/lib.php';

@set_time_limit(0);

$deployDir = realpath(__DIR__ . '/../deploy');
if ($deployDir === false) {
    fwrite(STDERR, "deploy/ not found\n");
    exit(1);
}

$outDir = isset($argv[1]) ? (string) $argv[1] : __DIR__ . '/../dist';
if ($outDir === '') {
    fwrite(STDERR, "output directory is empty\n");
    exit(1);
}
$outDir = rtrim(str_replace('\\', '/', $outDir), '/');

$removeTree = function (string $path) use (&$removeTree): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $removeTree($path . '/' . $name);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$copyTree = function (string $src, string $dst) use (&$copyTree): void {
    if (!is_dir($src)) {
        return;
    }
    if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
        fwrite(STDERR, "cannot create {$dst}\n");
        return;
    }
    foreach (array_diff(scandir($src) ?: [], ['.', '..']) as $name) {
        $from = $src . '/' . $name;
        $to = $dst . '/' . $name;
        if (is_dir($from)) {
            $copyTree($from, $to);
        } else {
            copy($from, $to);
        }
    }
};

$write = function (string $path, string $contents): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "cannot write {$path}\n");
        exit(1);
    }
};

/** 用 index.php 渲染一期，返回 HTML。 */
$renderIssue = function (string $date) use ($deployDir): string {
    $_GET = ['issue' => $date];
    $_REQUEST = $_GET;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = (string) parse_url((string) marginalia_config('site.url'), PHP_URL_HOST);

    ob_start();
    require $deployDir . '/index.php';
    $html = ob_get_clean();

    return is_string($html) ? $html : '';
};

$renderFeed = function () use ($deployDir): string {
    $_GET = [];
    $_REQUEST = [];
    ob_start();
    require $deployDir . '/feed.php';
    $xml = ob_get_clean();

    return is_string($xml) ? $xml : '';
};

$current = marginalia_current_issue_date();
$dates = marginalia_archive_dates(0);

if (!$dates) {
    fwrite(STDERR, "cache/ has no issue json; run bin/build-issue.php first\n");
    exit(1);
}
if (!in_array($current, $dates, true)) {
    fwrite(STDERR, "today ({$current}) is missing from cache\n");
    exit(1);
}

$removeTree($outDir);
if (!@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create {$outDir}\n");
    exit(1);
}

$pages = 0;
foreach ($dates as $date) {
    $html = $renderIssue($date);
    if (trim($html) === '') {
        fwrite(STDERR, "empty render for {$date}\n");
        exit(1);
    }
    $write($outDir . '/issue/' . $date . '/index.html', $html);
    $pages++;
}

$write($outDir . '/index.html', $renderIssue($current));
$write($outDir . '/feed.xml', $renderFeed());

$copyTree($deployDir . '/assets', $outDir . '/assets');

// GitHub Pages 默认会忽略下划线开头的文件；显式关掉 Jekyll，避免以后加资源时踩坑。
$write($outDir . '/.nojekyll', '');

$size = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $size += $file->getSize();
    }
}

echo json_encode([
    'output'     => $outDir,
    'issues'     => $pages,
    'latest'     => $current,
    'first'      => end($dates) ?: '',
    'feed_items' => $pages,
    'bytes'      => $size,
    'site_url'   => marginalia_abs('/'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
