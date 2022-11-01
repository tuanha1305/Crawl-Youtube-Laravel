<?php

namespace tuanictu97\apitube;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use stdClass;
use Symfony\Component\DomCrawler\Crawler;

class ApiTube
{
    /*
     * Javascript response regex
     */
    const JSON_REGEX = '/{"responseContext":(.*)}/';

    /*
     * HTML response regex
     */
    const BODY_REGEX = '/<body .*>(.*?)<\/body>/s';


    const SCRIPT_REGEX = '/<script .*>(.*?)<\/script>/s';

    /**
     * Force a JSON response for coverage
     */
    private bool $forceJson;

    public function __construct($forceJson = false)
    {
        $this->forceJson = $forceJson;
    }

    /**
     * Strip textual parts of a date for Carbon or strtotime
     *
     * @param $date
     * @return string|string[]
     */
    private function parseDate($date)
    {
        return str_replace(['Premiered ', 'Published on ', ','], ['', '', ''], $date);
    }

    /**
     * Parse an HTML response
     *
     * @param $html
     * @return object
     */
    public function getParseHTML($html)
    {
        preg_match(self::BODY_REGEX, $html, $matches);
        $crawler = new Crawler($matches[0]);

        $title = $crawler->filter('#eow-title')->text();
        $description = strip_tags(
            $crawler->filter('#eow-description')->html(),
            '<br>'
        );
        $viewCount = $crawler->filter('.watch-view-count')->text();
        $date = $crawler->filter('.watch-time-text')->text();
        return (object) [
            'title' => $title,
            'description' => str_replace('<br>', "\n", $description),
            'viewCount' => $this->viewsToInt($viewCount),
            'date' => $this->parseDate($date),
        ];
    }

    /**
     * Strip views into an integer
     *
     * @param string $text
     * @return int
     */
    public function viewsToInt(string $text): int
    {
        return (int) str_replace([' views', ','], ['', ''], $text);
    }

    /**
     * Search and return the 1st pages' results
     *
     * @param string $term
     * @return array
     */
    public function search(string $term, int $page = 1): array
    {
        $response = Http::withHeaders($this->forceJson ? [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36',
        ] : [
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ])
            ->get('https://www.youtube.com/results?search_query=' . urlencode($term) . 'page=' . $page);
        $html = $response->body();

        preg_match(self::JSON_REGEX, $html, $matches);

        if (isset($matches[0])) {
            return $this->searchParseJson($matches[0]);
        } else {
            return $this->searchParseHTML($html);
        }
    }

    public function convertTime(string $s)
    {
        if (str_contains($s, ' years ago')) {
            $s = str_replace(' years ago', 'y', $s);
            return $s;
        } else if (str_contains($s, ' year ago')) {
            $s = str_replace(' year ago', 'y', $s);
            return $s;
        }else if (str_contains($s, ' months ago')) {
            $s = str_replace(' months ago', 'mo', $s);
            return $s;
        }else if (str_contains($s, ' month ago')) {
            $s = str_replace(' month ago', 'mo', $s);
            return $s;
        } else if (str_contains($s, ' weeks ago')) {
            $s = str_replace(' weeks ago', 'w', $s);
            return $s;
        } else if (str_contains($s, ' week ago')) {
            $s = str_replace(' week ago', 'w', $s);
            return $s;
        }else if (str_contains($s, ' days ago')) {
            $s = str_replace(' days ago', 'd', $s);
            return $s;
        } else if (str_contains($s, ' day ago')) {
            $s = str_replace(' day ago', 'd', $s);
            return $s;
        }else if (str_contains($s, ' hours ago')) {
            $s = str_replace(' hours ago', 'h', $s);
            return $s;
        } 
        else if (str_contains($s, ' hour ago')) {
            $s = str_replace(' hour ago', 'h', $s);
            return $s;
        } else if (str_contains($s, ' minutes ago')) {
            $s = str_replace(' minutes ago', 'min', $s);
            return $s;
        }else if (str_contains($s, ' minute ago')) {
            $s = str_replace(' minute ago', 'min', $s);
            return $s;
        }else if (str_contains($s, ' seconds ago')) {
            $s = str_replace(' seconds ago', 'sec', $s);
            return $s;
        } else if (str_contains($s, ' second ago')) {
            $s = str_replace(' second ago', 'sec', $s);
            return $s;
        } else {
            return $s;
        }
    }

    /**
     * Parse a search term Javascript response
     *
     * @param $json
     * @return array
     */
    public function searchParseJson($json): array
    {
        $lastIndex = stripos($json, ";</script><script");

        $sub = substr($json, 0, $lastIndex);

        $contenJson = json_decode($sub);

        if ($contenJson == null) {
            throw new Exception('Invalid JSON response !');
        }

        $contents = $contenJson->contents
            ->twoColumnSearchResultsRenderer
            ->primaryContents
            ->sectionListRenderer
            ->contents[0]
            ->itemSectionRenderer
            ->contents;
        $results = [];
        foreach ($contents as $video) {
            if (property_exists($video, 'videoRenderer')) {
                if ($video->videoRenderer->videoId == null) {
                    continue;
                }
                preg_match_all('/\d+/', $video->videoRenderer->viewCountText->simpleText, $matches);

                $date = '00:00:00';
                if (substr_count($video->videoRenderer->lengthText->simpleText, ':') == 1) {
                    $date = date('h:m', strtotime($video->videoRenderer->lengthText->simpleText));
                }else{
                    $date = date('h:m:s', strtotime($video->videoRenderer->lengthText->simpleText));
                }

                $results[] = (object) [
                    'id' => $video->videoRenderer->videoId == null ? null : $video->videoRenderer->videoId,
                    'title' => $video->videoRenderer->title->runs[0]->text == null ? 'Unkown' : $video->videoRenderer->title->runs[0]->text,
                    'thumbnail' => $video->videoRenderer->thumbnail->thumbnails[0]->url == null ? 'Unkown' : $video->videoRenderer->thumbnail->thumbnails[0]->url,
                    'lengthTextSimpleText' => $date,
                    'viewCountText' => $video->videoRenderer->viewCountText->simpleText == null ? 0 : intval(join($matches[0])),
                    'publishedTimeText' => $video->videoRenderer->publishedTimeText->simpleText == null  ? '0d' : $this->convertTime($video->videoRenderer->publishedTimeText->simpleText),
                    'ownerChannelText' => $video->videoRenderer->ownerText->runs[0]->text == null  ? 'Unkown' : $video->videoRenderer->ownerText->runs[0]->text,
                    'thumbnailChannel' => $video->videoRenderer->channelThumbnailSupportedRenderers->channelThumbnailWithLinkRenderer->thumbnail->thumbnails[0]->url == null  ? 'Unkown' : $video->videoRenderer->channelThumbnailSupportedRenderers->channelThumbnailWithLinkRenderer->thumbnail->thumbnails[0]->url,
                ];
            }
        }
        return $results;
    }

    /**
     * Parse an HTML search response
     *
     * @param $html
     * @return array
     */
    private function searchParseHTML($html): array
    {
        preg_match(self::BODY_REGEX, $html, $matches);
        $crawler = new Crawler($matches[0]);
        $crawler = $crawler->filter('.item-section');
        $results = $crawler->filter('li')->each(fn ($node) => $this->parseNode($node));
        $parsed = (array_values(array_filter($results, fn ($r) => property_exists($r, 'id'))));
        return $parsed;
    }

    /**
     * Parse a particular result node
     *
     * @param Crawler $node
     * @return object
     */
    private function parseNode(Crawler $node): object
    {
        try {
            return (object) [
                'id' => substr(
                    $node->filter('.yt-lockup-title > a')->attr('href'),
                    9
                ),
                'title' => $node->filter('.yt-lockup-title > a')->attr('title'),
            ];
        } catch (Exception $exception) {
        }
        return new stdClass();
    }
}
