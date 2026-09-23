<?php

class TonyBaiGopherDailyBridge extends BridgeAbstract
{
    const NAME = 'Tony Bai GopherDaily Bridge';
    const URI = 'https://image.tonybai.com/gopherdaily/';
    const DESCRIPTION = 'Returns the latest GopherDaily (Go 语言技术日报) entries';
    const MAINTAINER = 'Custom';
    const CACHE_TIMEOUT = 1800; // 缓存 30 分钟

    // 清空配置参数，不再展示输入框与复选框
    const PARAMETERS = [];

    public function collectData()
    {
        // 固定提取最近 15 期
        $limit = 15;

        $headers = [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        ];

        $html = getSimpleHTMLDOM($this->getURI(), $headers);
        if (!$html) {
            returnServerError('Could not fetch ' . $this->getURI());
        }

        $bodyElem = $html->find('body', 0);
        $rawText = $bodyElem ? $bodyElem->innertext : $html->innertext;

        $cleanText = preg_replace('/<br\s*\/?>/i', "\n", $rawText);
        $cleanText = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $cleanText);
        $cleanText = strip_tags($cleanText, '<a>');
        $cleanText = html_entity_decode($cleanText, ENT_QUOTES, 'UTF-8');

        $pattern = '/(GopherDaily\s*[\r\n]+\s*(\d{8}))/i';
        $splits = preg_split($pattern, $cleanText, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($splits) < 3) {
            $pattern = '/(GopherDaily[^\d\r\n]*(\d{8}))/i';
            $splits = preg_split($pattern, $cleanText, -1, PREG_SPLIT_DELIM_CAPTURE);
        }

        $items = [];
        $total = count($splits);

        for ($i = 1; $i < $total; $i += 3) {
            if (!isset($splits[$i + 1]) || !isset($splits[$i + 2])) {
                break;
            }

            $dateStr = trim($splits[$i + 1]);
            $body = $splits[$i + 2];

            // 过滤底部签名
            $footerKeywords = [
                '编辑:Tony Bai',
                '编辑: Tony Bai',
                '编辑：Tony Bai',
                '编辑： Tony Bai',
                'GopherDaily项目'
            ];

            foreach ($footerKeywords as $kw) {
                $pos = mb_strpos($body, $kw);
                if ($pos !== false) {
                    $body = mb_substr($body, 0, $pos);
                    break;
                }
            }

            $body = trim($body);
            if (empty($body)) {
                continue;
            }

            // 格式化文本链接与换行
            $formattedHtml = preg_replace_callback(
                '/(?<!href=["\'])(https?:\/\/[^\s\r\n<>"\']+)/i',
                function ($m) {
                    return '<a href="' . $m[1] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
                },
                $body
            );

            $formattedHtml = nl2br(trim($formattedHtml));
            $timestamp = strtotime($dateStr) ?: time();

            $item = [];
            $item['title'] = "GopherDaily {$dateStr}";
            $item['uri'] = $this->getURI() . '#' . $dateStr;
            $item['timestamp'] = $timestamp;
            $item['author'] = 'Tony Bai';
            $item['content'] = $formattedHtml;

            $items[] = $item;

            if (count($items) >= $limit) {
                break;
            }
        }

        $this->items = $items;
    }
}
