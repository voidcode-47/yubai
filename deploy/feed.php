<?php
/**
 * 余白 MARGINALIA — RSS 2.0
 *
 * 每期一条 item，链接指向 /issue/<日期>/ 的固定地址。
 * 只读 cache/ 里的 JSON；当天缺期时顺手补一次，和老首页的行为一致。
 */

require_once __DIR__ . '/lib.php';

@set_time_limit(40);

$siteName = (string) marginalia_config('site.name');
$siteNameEn = (string) marginalia_config('site.name_en');
$siteTagline = (string) marginalia_config('site.tagline');
$siteDescription = (string) marginalia_config('site.description');

// 缺当天缓存时补一次；失败也不影响已存在的往期。
marginalia_ensure_issue(marginalia_current_issue_date());

$dates = array_slice(marginalia_archive_dates(0), 0, 30);
$issues = [];
foreach ($dates as $date) {
    $issue = marginalia_load_issue($date);
    if (is_array($issue)) {
        $issues[] = $issue;
    }
}

$xmlEscape = function ($value): string {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$xmlDate = function (string $iso, string $date) {
    $ts = $iso !== '' ? strtotime($iso) : false;
    if (!$ts) {
        $ts = marginalia_date_obj($date)->setTime((int) marginalia_config('site.drop_hour'), 0, 0)->getTimestamp();
    }
    return (new DateTimeImmutable('@' . $ts))
        ->setTimezone(marginalia_tz())
        ->format(DATE_RSS);
};

$issueLink = function (string $date): string {
    return marginalia_abs('/issue/' . rawurlencode($date) . '/');
};

$issueHtml = function (array $issue): string {
    $items = [];
    $projects = isset($issue['projects']) && is_array($issue['projects']) ? $issue['projects'] : [];
    if ($projects) {
        $items[] = '<p><strong>本期二十处</strong></p><ul>';
        foreach ($projects as $project) {
            $title = marginalia_esc((string) ($project['title'] ?? ''));
            $url = marginalia_esc((string) ($project['url'] ?? ''));
            $source = marginalia_esc((string) ($project['source'] ?? ''));
            $desc = trim((string) ($project['description'] ?? ''));
            $items[] = '<li><a href="' . $url . '">' . $title . '</a>'
                . ($source !== '' ? ' <small>' . $source . '</small>' : '')
                . ($desc !== '' ? '<br>' . marginalia_esc($desc) : '')
                . '</li>';
        }
        $items[] = '</ul>';
    }
    foreach (['news_cn' => '国内消息', 'news_intl' => '国际消息'] as $key => $label) {
        $news = isset($issue[$key]) && is_array($issue[$key]) ? $issue[$key] : [];
        if (!$news) {
            continue;
        }
        $items[] = '<p><strong>' . $label . '</strong></p><ul>';
        foreach ($news as $item) {
            $items[] = '<li><a href="' . marginalia_esc((string) ($item['url'] ?? '')) . '">'
                . marginalia_esc((string) ($item['title'] ?? '')) . '</a>'
                . ' <small>' . marginalia_esc((string) ($item['source'] ?? '')) . '</small></li>';
        }
        $items[] = '</ul>';
    }
    $items[] = '<p><a href="' . marginalia_abs('/issue/' . rawurlencode((string) $issue['date']) . '/') . '">在余白里读这一期 →</a></p>';
    return implode("\n", $items);
};

$lastBuild = !empty($issues[0]['generated_at']) ? (string) $issues[0]['generated_at'] : marginalia_iso_now();

if (!headers_sent()) {
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: public, max-age=600');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= $xmlEscape($siteName . ' ' . $siteNameEn) ?></title>
    <link><?= $xmlEscape(marginalia_abs('/')) ?></link>
    <description><?= $xmlEscape($siteTagline . '。' . $siteDescription) ?></description>
    <language>zh-cn</language>
    <lastBuildDate><?= $xmlEscape($xmlDate($lastBuild, (string) ($issues[0]['date'] ?? marginalia_current_issue_date()))) ?></lastBuildDate>
    <atom:link href="<?= $xmlEscape(marginalia_abs('/feed.xml')) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($issues as $issue): ?>
    <item>
      <title><?= $xmlEscape('第 ' . marginalia_issue_padded((string) $issue['date']) . ' 期 · ' . marginalia_format_date((string) $issue['date']) . ' · ' . (string) ($issue['theme'] ?? '')) ?></title>
      <link><?= $xmlEscape($issueLink((string) $issue['date'])) ?></link>
      <guid isPermaLink="true"><?= $xmlEscape($issueLink((string) $issue['date'])) ?></guid>
      <pubDate><?= $xmlEscape($xmlDate((string) ($issue['generated_at'] ?? ''), (string) $issue['date'])) ?></pubDate>
      <description><?= $xmlEscape($issueHtml($issue)) ?></description>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
