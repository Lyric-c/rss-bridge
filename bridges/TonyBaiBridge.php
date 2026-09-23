<?php

class TonyBaiBridge extends BridgeAbstract
{
    const NAME = 'Tony Bai Blog Bridge';
    const URI = 'https://tonybai.com/';
    const DESCRIPTION = 'Returns the latest articles from tonybai.com (Hugo PaperMod)';
    const MAINTAINER = 'Custom';
    const CACHE_TIMEOUT = 3600;

    const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Article Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Number of articles to return (max 30)'
            ],
            'full_content' => [
                'name' => 'Fetch Full Content',
                'type' => 'checkbox',
                'defaultValue' => true,
                'title' => 'Enable to fetch full article body from each post'
            ]
        ]
    ];

    public function collectData()
    {
        $limit = min((int)($this->getInput('limit') ?: 10), 30);
        $fetchFull = $this->getInput('full_content');

        // 设置标准浏览器 User-Agent，避免被 CDN 防爬拦截
        $headers = [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        ];

        $html = getSimpleHTMLDOM($this->getURI(), $headers);
        if (!$html) {
            returnServerError('Could not fetch ' . $this->getURI());
        }

        // Hugo PaperMod 主题的核心文章容器是 .post-entry
        $posts = $html->find('.post-entry');
        if (empty($posts)) {
            // 兼容回退
            $posts = $html->find('article, .entry');
        }

        $count = 0;
        foreach ($posts as $post) {
            if ($count >= $limit) {
                break;
            }

            // PaperMod 列表项标题通常是 .entry-header h2 或 直接链接
            $titleElem = $post->find('.entry-header h2, .entry-header h1, h2 a, a.entry-link', 0);
            if (!$titleElem) {
                continue;
            }

            // 获取详情链接：可能直接在 titleElem，也可能在整卡片的 a.entry-link
            $linkElem = $post->find('a.entry-link', 0) ?: $post->find('h2 a, a', 0);
            if (!$linkElem || empty($linkElem->href)) {
                continue;
            }

            $articleUrl = urljoin($this->getURI(), $linkElem->href);
            $title = trim($titleElem->plaintext);

            if (empty($title)) {
                continue;
            }

            $item = [];
            $item['uri'] = $articleUrl;
            $item['title'] = $title;
            $item['author'] = 'Tony Bai';

            // 提取日期：PaperMod 通常在 .entry-footer 中（如 "November 21, 2024 · 10 min · Tony Bai"）
            $footerElem = $post->find('.entry-footer', 0);
            if ($footerElem) {
                $timeElem = $footerElem->find('time', 0);
                if ($timeElem) {
                    $item['timestamp'] = strtotime($timeElem->getAttribute('datetime') ?: $timeElem->plaintext);
                } elseif (preg_match('/([A-Za-z]+ \d{1,2}, \d{4})/', $footerElem->plaintext, $m)) {
                    $item['timestamp'] = strtotime($m[1]);
                }
            }

	    // 抓取全文
            if ($fetchFull) {
                $articleHtml = getSimpleHTMLDOMCached($articleUrl, 86400, $headers);
                if ($articleHtml) {
                    $contentElem = $articleHtml->find('.post-content', 0);
                    if (!$contentElem) {
                        $contentElem = $articleHtml->find('article', 0);
                    }

                    if ($contentElem) {
                        // 1. 过滤结构性容器
                        $removeSelectors = [
                            '.post-footer',
                            '.related-posts',
                            '.share-buttons',
                            '.paginav',
                            '#comments',
                            'script',
                            'style',
                            'noscript'
                        ];

                        foreach ($contentElem->find(implode(',', $removeSelectors)) as $node) {
                            $node->outertext = '';
                        }

                        // 2. 移除顶部的“本文永久链接”段落
                        foreach ($contentElem->find('p, blockquote') as $p) {
                            if (strpos($p->plaintext, '本文永久链接') !== false) {
                                $p->outertext = '';
                                break;
                            }
                        }

                        // 3. 处理懒加载图片
                        foreach ($contentElem->find('img') as $img) {
                            if ($img->hasAttribute('data-src')) {
                                $img->src = $img->getAttribute('data-src');
                            }
                        }

                        // 转换相对链接为绝对链接
                        $rawHtml = defaultLinkTo($contentElem->innertext, $articleUrl);

                        // 4. 字符串级精准截断底部广告区
                        // 优先检查专栏广告关键词，直接截断广告所在的段落或其前面的分割线
			$adKeywords = [
				// 常见专栏文案特征
				'还在为写 Agent 框架',
				'从0 开始构建 Agent Harness',
				'从0开始构建Agent Harness',
				'我的新专栏',
				'新专栏',
				'极客时间',
				'大厂内部',
				'专栏上线',
				// 历史与常规引流特征
				'我的博客即将同步至腾讯云开发者社区',
				'微信扫码',
				'关注公众号',
				'扫描上方二维码',
				'扫码订阅'
			];

                        $splitPos = false;
                        foreach ($adKeywords as $kw) {
                            $pos = mb_strpos($rawHtml, $kw);
                            if ($pos !== false) {
                                // 找到了广告起始位置，向前寻找离它最近的一个 <hr> 或 <p>
                                $beforeAd = mb_substr($rawHtml, 0, $pos);
                                $lastHrPos = mb_strrpos($beforeAd, '<hr');

                                if ($lastHrPos !== false && ($pos - $lastHrPos) < 2000) {
                                    // 广告紧跟在 <hr> 后面，直接从该 <hr> 处切掉
                                    $splitPos = $lastHrPos;
                                } else {
                                    // 没找到或者距离太远，就从广告前一个段落标签截断
                                    $lastPPos = mb_strrpos($beforeAd, '<p');
                                    $splitPos = ($lastPPos !== false) ? $lastPPos : $pos;
                                }
                                break;
                            }
                        }

                        // 执行截断
                        if ($splitPos !== false) {
                            $rawHtml = mb_substr($rawHtml, 0, $splitPos);
                        }

                        $item['content'] = trim($rawHtml);
                    }
                }
            }

            // 降级使用列表摘要（PaperMod 的摘要在 .entry-content）
            if (empty($item['content'])) {
                $summaryElem = $post->find('.entry-content', 0);
                $item['content'] = $summaryElem ? trim($summaryElem->innertext) : $title;
            }

            $this->items[] = $item;
            $count++;
        }
    }
}
