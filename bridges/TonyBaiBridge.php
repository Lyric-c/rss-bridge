<?php

class TonyBaiBridge extends BridgeAbstract
{
    const NAME = 'Tony Bai';
    const URI = 'https://tonybai.com/articles/';
    const DESCRIPTION = 'Blog Tony Bai';
    const MAINTAINER = 'Irelia';
    const PARAMETERS = []; 
    const CACHE_TIMEOUT = 3600; 

    public function collectData()
    {
        $item = []; 

        $html = getSimpleHTMLDOM(self::URI);
        $articles = $html->find("div.post-content li");
        foreach($articles as $i => $article) {
            if ($i>10) break;
            $a=$article->find('a', 0);

            $post=getSimpleHTMLDOM($a->href);
            $content=$post->find('div.post-content');
            if(count($content)==0) continue;

            $pTag=$content[0]->find('p');
            $total=count($pTag);        
            //Delete the first three p nodes
            for($i=0;$i<3;$i++){
                $pTag[$i]->outertext='';
            }
            //Delete qr images
            $imgTag = $content[0]->find('img');
            $imgCount = count($imgTag);
            for($i=0;$i<$imgCount;$i++) {
                if (!str_ends_with($imgTag[$i]->src, '-qr.png')) {
                    continue;
                }
                $imgTag[$i]->outertext='';
            }
            $hrTag=$content[0]->find('hr');
            $total = count($hrTag);
            if ($total >= 4) {
                    $target = $hrTag[$total - 4];
                    $next = $target->next_sibling();

                    while ($next) {
                            error_log("delete");
                            $tmp = $next->next_sibling(); 
                            $next->outertext = '';
                            $next = $tmp;
                    }
            }
            $item['uri']=$a->href;
            $item['title']=$a->plaintext;
            $item['content']=$content[0]->innertext;
            $this->items[]=$item;
        }
    }
}