<?php
/**
 * 余白 MARGINALIA — 站点配置
 *
 * 这个文件只放配置，不放逻辑。改完后直接覆盖上传即可。
 * 不需要数据库；内容保存在 cache/ 里的每日 JSON 文件中。
 */

return [
    'site' => [
        'name'        => '余白',
        'name_en'     => 'MARGINALIA',
        'tagline'     => '一份关于互联网角落的日刊',
        'description' => '每天二十处自动搜集的网站与项目，再加十条国内外消息。给好奇心，留一点余白。',
        'url'         => getenv('MARGINALIA_SITE_URL') ?: 'https://yubai.66ghz.com',
        // 托管在子目录时填 /子目录；GitHub Pages 项目站会用到。根目录留空。
        'base_path'   => getenv('MARGINALIA_BASE_PATH') ?: '',
        // 导出静态站时置 1：页面不再调用 refresh.php。
        'static'      => getenv('MARGINALIA_STATIC') === '1',
        'email'       => '2951065718@qq.com',
        'timezone'    => 'Asia/Shanghai',
        'drop_hour'   => 9, // 北京时间每天几点更新；页面倒计时会跟着变
        'locale'      => 'zh_CN',
    ],

    'cache' => [
        'dir'            => __DIR__ . '/cache',
        'keep_days'      => 21,   // 最多保留多少天的存档
        'fail_cooldown'  => 900,  // 抓取失败后，多少秒内不重复尝试
        'write_json_log' => true, // 把简要抓取日志写进 cache/error.log
    ],

    'refresh' => [
        // 强烈建议改成一串随机字符。留空时，force 刷新不受保护。
        // 自动刷新（refresh.php?auto=1）不需要 key，但只会在缺期或降级时真的抓取。
        'key' => '',
    ],

    'http' => [
        'timeout'         => 12,
        'connect_timeout' => 7,
        'max_bytes'       => 3145728, // 单文件 3 MB 上限
        // 主机禁用 curl_multi_* 时按顺序抓取：单个请求上限 + 整批总预算，避免超过 60 秒执行限制。
        'sequential_timeout' => 8,
        'total_budget'       => 40,
        'ca_bundle'       => __DIR__ . '/data/cacert.pem',
        'user_agent'      => 'Mozilla/5.0 (compatible; YubaiBot/1.0; +https://yubai.66ghz.com/)',
        'accept_language' => 'zh-CN,zh;q=0.9,en;q=0.8',
    ],

    'counts' => [
        'projects'   => 20,
        'news_cn'    => 5,
        'news_intl'  => 5,
    ],

    // 每个来源先按配额取；某个来源失效时，其他来源可以加到 max。
    'quotas' => [
        'hn'       => ['target' => 7, 'max' => 10],
        'github'   => ['target' => 6, 'max' => 10],
        'ph'       => ['target' => 4, 'max' => 7],
        'awwwards' => ['target' => 3, 'max' => 5],
    ],

    'sources' => [
        'projects' => [
            'hn' => [
                'type'    => 'hn',
                'label'   => 'Hacker News',
                'url'     => 'https://hn.algolia.com/api/v1/search_by_date?tags=show_hn&hitsPerPage=100',
                'enabled' => true,
            ],
            // GitHub Trending 不依赖 API token，优先使用；API 只作为备用。
            'github_trending' => [
                'type'    => 'github_trending',
                'label'   => 'GitHub',
                'url'     => 'https://github.com/trending?since=daily',
                'enabled' => true,
            ],
            'github' => [
                'type'    => 'github',
                // {since} 会替换成 14 天前的日期
                'url'     => 'https://api.github.com/search/repositories?q=created:%3E{since}+stars:%3E%3D30+fork:false&sort=stars&order=desc&per_page=50',
                'label'   => 'GitHub',
                'enabled' => true,
            ],
            'ph' => [
                'type'    => 'ph',
                'label'   => 'Product Hunt',
                'url'     => 'https://www.producthunt.com/feed',
                'enabled' => true,
            ],
            'awwwards' => [
                'type'    => 'awwwards',
                'label'   => 'Awwwards',
                'url'     => 'https://www.awwwards.com/feed/',
                'enabled' => true,
            ],
        ],

        // 国内：时政 + 社会 + 商业 + 科技，轮转取五条
        'news_cn' => [
            'chinanews_china' => [
                'type'     => 'rss',
                'label'    => '中国新闻网',
                'url'      => 'https://www.chinanews.com.cn/rss/china.xml',
                'lang'     => 'zh',
                'fallback' => false,
                'enabled'  => true,
            ],
            'chinanews_scroll' => [
                'type'     => 'rss',
                'label'    => '中国新闻网',
                'url'      => 'https://www.chinanews.com.cn/rss/scroll-news.xml',
                'lang'     => 'zh',
                'fallback' => false,
                'enabled'  => true,
            ],
            'jiemian' => [
                'type'     => 'rss',
                'label'    => '界面新闻',
                'url'      => 'https://a.jiemian.com/index.php?m=article&a=rss',
                'lang'     => 'zh',
                'fallback' => false,
                // 目标主机连续多次在 8 秒内连不上界面 RSS；先关闭，恢复后改回 true。
                'enabled'  => false,
            ],
            'ifanr' => [
                'type'     => 'rss',
                'label'    => '爱范儿',
                'url'      => 'https://www.ifanr.com/feed',
                'lang'     => 'zh',
                'fallback' => false,
                'enabled'  => true,
            ],
            'sspai' => [
                'type'     => 'rss',
                'label'    => '少数派',
                'url'      => 'https://sspai.com/feed',
                'lang'     => 'zh',
                'fallback' => false,
                // 少数派是文章站，社区速递 / 教程居多，放进新闻栏只会挤掉消息。
                'enabled'  => false,
            ],
        ],

        // 国际：优先中文源；英文源只在中英文源都不够五条时补位
        'news_intl' => [
            'chinanews_world' => [
                'type'     => 'rss',
                'label'    => '中国新闻网',
                'url'      => 'https://www.chinanews.com.cn/rss/world.xml',
                'lang'     => 'zh',
                'fallback' => false,
                'enabled'  => true,
            ],
            'un_news_zh' => [
                'type'     => 'rss',
                'label'    => '联合国新闻',
                'url'      => 'https://news.un.org/feed/subscribe/zh/news/all/rss.xml',
                'lang'     => 'zh',
                'fallback' => false,
                'enabled'  => true,
            ],
            'cgtn_world' => [
                'type'     => 'rss',
                'label'    => 'CGTN',
                'url'      => 'https://www.cgtn.com/subscribe/rss/section/world.xml',
                'lang'     => 'en',
                'fallback' => true,
                'enabled'  => true,
            ],
            'npr_world' => [
                'type'     => 'rss',
                'label'    => 'NPR World',
                'url'      => 'https://feeds.npr.org/1004/rss.xml',
                'lang'     => 'en',
                'fallback' => true,
                'enabled'  => true,
            ],
        ],
    ],

    'filters' => [
        'blocked_domains' => [
            'twitter.com', 'x.com', 'facebook.com', 'instagram.com', 'linkedin.com',
            'tiktok.com', 'youtube.com', 'youtu.be', 'pinterest.com', 'reddit.com',
            'medium.com', 'news.ycombinator.com', 'docs.google.com', 'forms.gle',
            'bit.ly', 't.co', 't.me', 'discord.gg', 'wa.me', 'patreon.com',
        ],
        'project_blocked_words' => [
            'casino', 'betting', 'gambling', 'slot online', 'togel', 'judi',
            'porn', 'xxx', 'nude', 'onlyfans', 'crypto airdrop', 'viagra', 'cialis',
            'essay writing', 'assignment help', 'homework help', 'recovery service',
        ],
        'github_blocked_words' => [
            'awesome-', 'awesome ', 'leetcode', 'interview', 'tutorial', 'course',
            'roadmap', 'cheat sheet', 'cheatsheet', 'free-programming', 'study notes',
            '教程', '面试', '学习笔记', '资源合集', '课程', '题库', '笔记',
        ],
        'news_blocked_words' => [
            '广告', '版权声明', '免责声明', '招聘', '订阅', '登录', '注册',
            'app下载', '网站地图', '联系我们', '关于我们', '首页',
        ],
        // 新闻栏只收“消息”，不收栏目化 / 软稿体裁。宁可少一条，也不让
        // 「社区速递」「音乐家寻访」这类内容占掉国际版的五个位置。
        'news_soft_words' => [
            '社区速递', '派友', '剁手', '一日一技', '周报', '日报', '盘点',
            '清单', '指南', '教程', '攻略', '游记', '美食', '食谱',
            '音乐家', '艺术家', '画家', '作家', '演出', '音乐会', '展览',
            '侧记', '特写', '专访', '访谈', '寻访', '故事', '纪实',
            '组图', '图集', '直播', '综艺', '明星', '网红', '萌宠',
            '节气', '民俗', '非遗', '读书', '书评',
            // 国际版里常见的“软稿”：节庆、联赛、论坛专栏、援外故事。
            '灯船节', '板球', '联赛', '旅游', '论坛', '足迹', '读懂',
            '援外医疗队', '焕发',
        ],
        'github_min_stars'  => 30,
        'hn_min_points'     => 10,
        'hn_min_comments'   => 5,
        'ph_max_age_days'   => 10,
        'hn_max_age_days'   => 4,
        'aww_max_age_days'  => 7,
        'github_max_age_days' => 14,
        'news_max_age_hours' => 24,
        'news_per_source_cap' => 2,
    ],
];
