<?php
require_once(preg_replace("#/bin(|/.+)$#", "/vendor/autoload.php", __DIR__));

class EspnGolfLeaderboardScraper extends Bpaulsen314\Scrapero\Scraper
{
    protected $_config = [
        "seed" => "http://www.espn.com/golf/leaderboard",
        "summary" => "Scraper for: http://www.espn.com/golf/leaderboard"
    ];

    protected function _scrape($page)
    {
        $eventTitle = $page->getTextByXpath('//header[contains(@class, "matchup-header")]//h1');
        $this->log($eventTitle);

        $data = $page->getTableByXpath(
            '//section[contains(@class,  "matchup-content")]//table[contains(@class, "leaderboard-table")]',
            [
                "cellFunction" => function($col, $element) use ($page) {
                    if ($col === "player") {
                        $element = $page->getElementByXpath('.//a[contains(@class, "full-name")]', $element);
                    }
                    return $element;
                },
                "rowFunction" => function($i, $row) use ($page) {
                    $class = $row->getAttribute("class");
                    preg_match("#\bplayer-overview-(?<id>\d+)\b#", $class, $matches);
                    return [$row, ["id" => (int) $matches["id"]]];
                }
            ]
        );
        $this->log($data);
    }
}

if (php_sapi_name() === "cli") {
    $scraper = new EspnGolfLeaderboardScraper();
    $scraper->start($argv);
}
