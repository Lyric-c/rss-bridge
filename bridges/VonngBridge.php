<?php

class VonngBridge extends BridgeAbstract
{
    const NAME = 'Vonng Blog Bridge';
    const URI = 'https://blog.vonng.com/';
    const DESCRIPTION = 'Returns the latest articles from blog.vonng.com';
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

        $html = getSimpleHTMLDOM($this->getURI());
        if (!$html) {
            returnServerError('Could not fetch ' . $this->getURI());
        }

        $posts = $html->find('article, .post-entry, .post-item');
        if (empty($posts)) {
            $posts = $html->find('main li, .posts li');
        }

        $count = 0;
        foreach ($posts as $post) {
            if ($count >= $limit) {
                break;
            }

            $titleElem = $post->find('h1 a, h2 a, a.post-link, a', 0);
            if (!$titleElem) {
                continue;
            }

            $articleUrl = urljoin($this->getURI(), $titleElem->href);
            $title = trim($titleElem->plaintext);

            if (empty($title) || strpos($titleElem->href, '#') === 0) {
                continue;
            }

            $item = [];
            $item['uri'] = $articleUrl;
            $item['title'] = $title;

            $timeElem = $post->find('time, .post-date, .date', 0);
            if ($timeElem) {
                $item['timestamp'] = strtotime($timeElem->getAttribute('datetime') ?: $timeElem->plaintext);
            }

            $authorElem = $post->find('.author, .post-author', 0);
            $item['author'] = $authorElem ? trim($authorElem->plaintext) : 'Vonng';

            if ($fetchFull) {
                // 建议先使用 0 观察效果，调试完毕可改回 86400 提升性能
                $articleHtml = getSimpleHTMLDOMCached($articleUrl, 86400);
                if ($articleHtml) {
                    $contentElem = $articleHtml->find('.td-content, article', 0);
                    if ($contentElem) {
                        // 精准剔除无关节点，绝对不要模糊删除大容器
                        $badSelectors = [
                            // 顶部 Docsy 操作栏组件与按钮
                            '.td-page-meta',
                            '.td-page-actions',
                            '.td-page-actions__primary',
                            'button',
                            'ul[aria-label="操作"]',
                            '[aria-label="操作"]',
                            'span[aria-live="polite"]',
                            '.td-breadcrumbs',
                            // 评论区（包括 giscus/utterances 及 fallback）
                            '.comments',
                            '#comments',
                            '.giscus',
                            '.utterances',
                            'noscript',
                            // 导航与脚本
                            'nav',
                            'footer',
                            'script',
                            'style',
                            'aside'
                        ];

                        foreach ($contentElem->find(implode(',', $badSelectors)) as $node) {
                            $node->outertext = '';
                        }

                        $item['content'] = defaultLinkTo($contentElem->innertext, $articleUrl);
                    }
                }
            }

            if (empty($item['content'])) {
                $summaryElem = $post->find('.post-summary, .entry-summary, p', 0);
                $item['content'] = $summaryElem ? trim($summaryElem->innertext) : $title;
            }

            $this->items[] = $item;
            $count++;
        }
    }
}
