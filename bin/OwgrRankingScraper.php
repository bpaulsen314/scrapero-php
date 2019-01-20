<?php
require_once(preg_replace("#/bin(|/.+)$#", "/vendor/autoload.php", __DIR__));

class OwgrRankingScraper extends Bpaulsen314\Scrapero\Scraper
{
    protected $_config = [
        "seed" => "http://www.owgr.com/ranking?pageNo=1&pageSize=5",
        "summary" => "Scraper for: http://www.owgr.com/ranking"
    ];

    protected function _scrape($page)
    {
        $data = $page->getTableByXpath(
            '//section[@id="ranking_table"]//table',
            [
                "rowQuery" => ".//tr",
                "cellFunction" => function($col, $element) use ($page) {
                    if ($col === "ctry") {
                        $element = $page->getElementByXpath(".//img", $element)->getAttribute("title");
                    } else if ($col === "name") {
                        $href = $page->getElementByXpath(".//a", $element)->getAttribute("href");
                        preg_match("#playerID=(?<id>\d+)#", $href, $matches);
                        $element = [
                            "id" => $matches["id"],
                            $col => $element
                        ];
                    }
                    return $element;
                }
            ]
        );

        $data = array_map(
            function($arr) {
                return [
                    "id" => (int) $arr["id"],
                    "name" => $arr["name"],
                    "country" => $arr["ctry"],
                    "rank" => (int) $arr["thisWeek"],
                    "totalPoints"=> (preg_match("#\d#", $arr["totalPoints"]) ? $arr["totalPoints"] : null)
                ];
            },
            $data
        );

        return array_filter($data, function($arr) { return isset($arr["totalPoints"]); });
    }
}

if (php_sapi_name() === "cli") {
    $scraper = new OwgrRankingScraper();
    $scraper->start($argv);
}
