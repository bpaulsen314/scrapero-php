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
        $record = [];

        $matchupHeader = $page->getElementByXpath('//header[contains(@class, "matchup-header")]');
        $eventTitle = $page->getTextByXpath('.//h1', $matchupHeader);
        $eventTimeFrame = $page->getTextByXpath('.//div[contains(@class, "date")]', $matchupHeader);
        $eventLocation = $page->getTextByXpath('.//div[contains(@class, "location")]', $matchupHeader);
        $eventCourseDetails = $page->getTextsByXpath('.//div[contains(@class, "course-detail")]', $matchupHeader);
        
        $this->log($eventTitle);
        $this->log($eventTimeFrame);
        $this->log($eventLocation);
        $this->log($eventCourseDetails);
        die();

        $data = $page->getTableByXpath(
            '//section[contains(@class,  "matchup-content")]//table[contains(@class, "leaderboard-table")]',
            [
                "cellFunction" => function($col, $element) use ($page) {
                    if ($col === "player") {
                        $element = $page->getElementByXpath('.//a[contains(@class, "full-name")]', $element);
                    } else if ($col === "teeTime") {
                        $element = $page->getElementByXpath('.//span[contains(@class, "date-container")]', $element);
                        $element = $element->getAttribute("data-date");
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

        return $record;
    }
}

if (php_sapi_name() === "cli") {
    $scraper = new EspnGolfLeaderboardScraper();
    $scraper->start($argv);
}
