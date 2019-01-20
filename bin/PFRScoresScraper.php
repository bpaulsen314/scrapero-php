<?php
require_once(preg_replace("#/bin(|/.+)$#", "/vendor/autoload.php", __DIR__));

use Bpaulsen314\Scrapero\WebPage;

class PFRScoresScraper extends Bpaulsen314\Scrapero\Scraper
{
    protected $_config = [
        "seed" => "https://www.pro-football-reference.com/boxscores/",
        "summary" => "Scraper for: https://www.pro-football-reference.com/boxscores/",
        "arguments" => [
            "year" => [
                "short" => "y",
                "description" => "Year for which to scrape boxscore data."
            ],
            "week" => [
                "short" => "w",
                "description" => "Week of the year for which to scrape boxscore data."
            ]
        ]
    ];

    protected function _crawl($page, $options = [])
    {
        $pages = [];

        $this->log();
        $this->logIndent();

        $currentYear = null;
        $sectionHeaderTexts = $page->getTextsByXpath('//h2');
        foreach ($sectionHeaderTexts as $text) {
            if (preg_match("#(?<year>\d+)\s*Week\s*(?<week>\d+)#i", $text, $matches)) {
                $currentYear = $matches["year"];
                $currentWeek = $matches["week"];
                break;
            }
        }

        $this->log("Current year-week: {$currentYear}-{$currentWeek}.");
        $this->log();

        $years = $this->_arguments["year"] ?: $currentYear;
        $weeks = $this->_arguments["week"] ?: 
            ($this->_arguments["year"] ? "all" : $currentWeek);
        
        if (preg_match("#^a(ll)?$#i", $years)) {
            $years = $page->getAttributesByXpath(
                "value", '//select[@name="year_id"]/option'
            );
        } else {
            $years = preg_split("#\s*,\s*#", $years, -1, PREG_SPLIT_NO_EMPTY);
        }
        sort($years, SORT_NUMERIC);
        $years = array_reverse($years);

        if (preg_match("#^a(ll)?$#i", $weeks)) {
            $weeks = $page->getAttributesByXpath(
                "value", '//select[@name="week"]/option'
            );
        } else {
            $weeks = preg_split("#\s*,\s*#", $weeks, -1, PREG_SPLIT_NO_EMPTY);
        }
        sort($weeks, SORT_NUMERIC);
        $weeks = array_reverse($weeks);

        $this->log("Looking for boxscores ... ");
        $this->logIndent();
        $this->log("Year(s): " . implode(", ", $years) . ".");
        $this->log("Week(s): " . implode(", ", $weeks) . ".");
        $this->log();

        $weekUris = [];
        foreach ($years as $y) {
            foreach ($weeks as $w) {
                $uri = "https://www.pro-football-reference.com/years/{$y}/week_{$w}.htm";
                if ($y < $currentYear || $w <= $currentWeek) {
                    $weekUris[] = $uri;
                }
            }
        }

        foreach ($weekUris as $uri) {
            $this->log("Crawling \"{$uri}\" ... ", false);
            $weekPage = new WebPage($uri);
            $gamelinkHrefs = $weekPage->getAttributesByXpath(
                "href", '//td[contains(@class, "gamelink")]/a'
            );
            $this->log(count($gamelinkHrefs) . " found ... ", false);
            foreach ($gamelinkHrefs as $href) {
                $pages[] = "https://www.pro-football-reference.com" . $href;
            }
            $this->log("DONE!");
        }

        $this->logUnindent();
        $this->log("DONE!");
        $this->logUnindent();

        return $pages;
    }

    protected function _finalize($data, $options = [])
    {
        $teamAggs = [];

        foreach ($data as $record) {
            $gameId = $record["game_id"];
            $year = $record["game_year"];
            foreach (["away", "home"] as $type) {
                $otherType = ($type === "home" ? "away" : "home");
                $abbrev = $record["{$type}_abbreviation"];
                $score = $record["{$type}_full_score"];
                $oppScore = $record["{$otherType}_full_score"];
                if (!isset($teamAggs[$abbrev])) {
                    $teamAggs[$abbrev] = [];
                }
                if (!isset($teamAggs[$abbrev][$year])) {
                    $teamAggs[$abbrev][$year] = [];
                }
                $teamAggs[$abbrev][$year][$gameId] = [
                    "gamePointsFor" => $score,
                    "gamePointsAgainst" => $oppScore
                ];
            }
        }

        foreach ($teamAggs as $teamAbbrev => &$yearAggs) {
            foreach ($yearAggs as $year => &$gameAggs) {
                ksort($gameAggs);
                $games = 0;
                $yearPointsFor = 0;
                $yearPointsAgainst = 0;
                foreach ($gameAggs as $gameId => &$agg) {
                    if ($games > 0) {
                        $agg["avgGamePointsFor"] = round(
                            ($yearPointsFor / $games), 2
                        );
                        $agg["avgGamePointsAgainst"] = round(
                            ($yearPointsAgainst / $games), 2
                        );
                    }
                    $yearPointsFor += $agg["gamePointsFor"];
                    $yearPointsAgainst += $agg["gamePointsAgainst"];
                    $games++;
                }
            }
        }

        foreach ($data as &$record) {
            $gameId = $record["game_id"];
            $year = $record["game_year"];
            foreach (["away", "home"] as $type) {
                $abbrev = $record["{$type}_abbreviation"];
                $agg = $teamAggs[$abbrev][$year][$gameId];
                $record["{$type}_avg_score"] = $agg["avgGamePointsFor"];
                $record["{$type}_avg_score_against"] = $agg["avgGamePointsAgainst"];
            }
        }

        if ($this->_debug > 0) {
            // $this->log($teamAggs);
            // $this->log($data);
        }

        return $data;
    }

    protected function _scrape($page, $options = [])
    {
        $data = [
            "game_id" => null,
            "game_type" => null,
            "game_year" => null,
            "game_week" => null,
            "game_time" => null,
            "conditions_surface" => null,
            "conditions_surface_artifical" => null,
            "conditions_roof" => null,
            "conditions_indoor" => null,
            "conditions_temperature_f" => null,
            "conditions_wind_mph" => null,
            "betting_favorite" => null,
            "betting_full_spread" => null,
            "betting_full_home_spread" => null,
            "betting_full_total" => null,
            "away_city" => null,
            "away_mascot" => null,
            "away_abbreviation" => null,
            "away_avg_score" => null,
            "away_avg_score_against" => null,
            "away_q1_score" => null,
            "away_q2_score" => null,
            "away_q3_score" => null,
            "away_q4_score" => null,
            "away_full_score" => null,
            "home_city" => null,
            "home_mascot" => null,
            "home_abbreviation" => null,
            "home_avg_score" => null,
            "home_avg_score_against" => null,
            "home_q1_score" => null,
            "home_q2_score" => null,
            "home_q3_score" => null,
            "home_q4_score" => null,
            "home_full_score" => null
        ];

        $this->_scrapeGameInfo($data, $page, $options);
        $this->_scrapeBettingAndConditionsInfo($data, $page, $options);
        $this->_scrapeLineScore($data, $page, $options);
        $this->_scrapeTeamStats($data, $page, $options);

        if ($this->_debug > 0) {
            $this->log($data);
            die();
        }

        return $data;
    }

    protected function _scrapeBettingAndConditionsInfo(&$data, $page, $options = [])
    {
        if ($page->getElementByXpath('//div[@id="all_game_info"]')) {
            $page->uncomment('//div[@id="all_game_info"]/comment()');
        }

        $gameInfo = $page->getTableByXpath(
            '//table[@id="game_info"]',
            [
                "columnFunction" => function($i, $col) use ($page) {
                    if ($i == 0) {
                        return ["k", "v"];
                    }
                    return false;
                },
                "columnQuery" => './/tr[1]//td',
                "rowQuery" => './/tr[th]'
            ]
        );

        foreach ($gameInfo as $info) {
            if (preg_match("#\bLINE\b#i", $info["k"])) {
                preg_match(
                    "#^(?<city>.+)\s+(?<mascot>\S+)\s+-?(?<line>\S+)$#", 
                    $info["v"], 
                    $matches
                );
                $data["betting_favorite"] = (
                    strcasecmp($matches["mascot"], $data["home_mascot"]) == 0 ?
                        "HOME" : "AWAY"
                );
                $data["betting_full_spread"] = (float) $matches["line"];
                $data["betting_full_home_spread"] = (
                    $data["betting_favorite"] === "HOME" ? "-" : "+"
                ) . $data["betting_full_spread"];
            } else if (preg_match("#\bOVER\b#i", $info["k"])) {
                $pieces = explode(" ", $info["v"]);
                $data["betting_full_total"] = (int) $pieces[0];
            } else if (preg_match("#\bWEATHER\b#i", $info["k"])) {
                preg_match("#(?<temp>\d+)\s+degrees#i", $info["v"], $matches);
                $data["conditions_temperature_f"] = (int) $matches["temp"];
                preg_match("#wind\s+(?<wind>\d+)#i", $info["v"], $matches);
                $data["conditions_wind_mph"] = (int) $matches["wind"];
            } else if (preg_match("#\bSURFACE\b#i", $info["k"])) {
                $data["conditions_surface"] = trim($info["v"]);
                $data["conditions_surface_artifical"] = 
                    (!preg_match("#\bGRASS\b#i", $data["conditions_surface"]));
            } else if (preg_match("#\bROOF\b#i", $info["k"])) {
                $data["conditions_roof"] = trim($info["v"]);
                $data["conditions_indoor"] = 
                    (!preg_match("#\bOUTDOORS\b#i", $data["conditions_roof"]));
            }
        }
    }

    protected function _scrapeGameInfo(&$data, $page, $options = [])
    {
        $page->uncomment('//div[@id="all_other_scores"]/comment()');
        $weekLink = $page->getElementByXpath(
            '//div[contains(@class, "game_summaries")]/h2/a'
        );
        $data["game_type"] = (
            preg_match("#playoff#i", $weekLink->textContent) ?
                "PLAYOFF" : "REGULAR"
        );
        preg_match(
            "#/years/(?<year>\d+)/week_(?<week>\d+)#", 
            $weekLink->getAttribute("href"), 
            $matches
        );
        $data["game_year"] = (int) $matches["year"];
        $data["game_week"] = (int) $matches["week"];
        if ($data["game_type"] === "PLAYOFF") {
            $header = $page->getTextByXpath('//div[@id="content"]/h1');
            preg_match("#^(?<week>[^-]+)#", $header, $matches);
            $data["game_week"] = trim($matches["week"]);
        }

        $teamLinks = $page->getElementsByXpath('//div[contains(@class, "scorebox")]//strong/a');
        foreach (["away", "home"] as $type) {
            $link = array_pop($teamLinks);
            preg_match("#^(?<city>.*)\s(?<mascot>\S*)$#", $link->textContent, $matches);
            $data["{$type}_city"] = $matches["city"];
            $data["{$type}_mascot"] = $matches["mascot"];
            preg_match("#/teams/(?<abbr>[^/]*)#", $link->getAttribute("href"), $matches);
            $data["{$type}_abbreviation"] = $this->_getTeamAbbreviation(
                $data["{$type}_city"], $data["{$type}_mascot"]
            );
        }

        $scoreboxMeta = $page->getTextsByXpath(
            '//div[contains(@class, "scorebox_meta")]/div'
        );

        preg_match("#(?<time>\d+:\d+\s*[ap]m?)#i", $scoreboxMeta[1], $matches);
        $data["game_time"] = implode( 
            " ", [$scoreboxMeta[0], $matches["time"], "America/New_York"]
        );
        $data["game_time"] = date("c", strtotime($data["game_time"]));

        $data["game_id"] = $this->_getGameId(
            $data["game_time"], $data["home_abbreviation"]
        );
    }

    protected function _scrapeLineScore(&$data, $page, $options = [])
    {
        $lineScore = $page->getTableByXpath(
            '//table[contains(@class, "linescore")]',
            [
                "columnFunction" => function($i, $col) use ($page) {
                    if ($i <= 1) {
                        $col = false;
                    } else if (preg_match("#^\s*\d\s*$#", $col->textContent)) {
                        $col = "q" . $col->textContent;
                    }
                    return $col;
                }
            ]
        );

        foreach (["home", "away"] as $type) {
            if ($lineScore) {
                $l = array_pop($lineScore);
                $data["{$type}_q1_score"]       = (int) $l["q1"];
                $data["{$type}_q2_score"]       = (int) $l["q2"];
                $data["{$type}_q3_score"]       = (int) $l["q3"];
                $data["{$type}_q4_score"]       = (int) $l["q4"];
                $data["{$type}_full_score"]    = (int) $l["final"];
                $data["{$type}_full_score"]    = (int) $l["final"];
            }
        }
    }

    protected function _scrapeTeamStats(&$data, $page, $options = [])
    {
        if ($page->getElementByXpath('//div[@id="all_team_stats"]')) {
            $page->uncomment('//div[@id="all_team_stats"]/comment()');
        }

        $teamStats = $page->getTableByXpath(
            '//table[@id="team_stats"]',
            [
                "columnFunction" => function($i, $col) use ($page) {
                    if ($i == 0) {
                        return ["k", "away_v", "home_v"];
                    }
                    return false;
                },
                "columnQuery" => './/tr[1]//td',
                "rowQuery" => './/tr[th]'
            ]
        );

        $this->log($teamStats);

        /*
        foreach ($gameInfo as $info) {
            if (preg_match("#\bLINE\b#i", $info["k"])) {
                preg_match(
                    "#^(?<city>.+)\s+(?<mascot>\S+)\s+-?(?<line>\S+)$#", 
                    $info["v"], 
                    $matches
                );
                $data["betting_favorite"] = (
                    strcasecmp($matches["mascot"], $data["home_mascot"]) == 0 ?
                        "HOME" : "AWAY"
                );
                $data["betting_full_spread"] = (float) $matches["line"];
                $data["betting_full_home_spread"] = (
                    $data["betting_favorite"] === "HOME" ? "-" : "+"
                ) . $data["betting_full_spread"];
            } else if (preg_match("#\bOVER\b#i", $info["k"])) {
                $pieces = explode(" ", $info["v"]);
                $data["betting_full_total"] = (int) $pieces[0];
            } else if (preg_match("#\bWEATHER\b#i", $info["k"])) {
                preg_match("#(?<temp>\d+)\s+degrees#i", $info["v"], $matches);
                $data["conditions_temperature_f"] = (int) $matches["temp"];
                preg_match("#wind\s+(?<wind>\d+)#i", $info["v"], $matches);
                $data["conditions_wind_mph"] = (int) $matches["wind"];
            } else if (preg_match("#\bSURFACE\b#i", $info["k"])) {
                $data["conditions_surface"] = trim($info["v"]);
                $data["conditions_surface_artifical"] = 
                    (!preg_match("#\bGRASS\b#i", $data["conditions_surface"]));
            } else if (preg_match("#\bROOF\b#i", $info["k"])) {
                $data["conditions_roof"] = trim($info["v"]);
                $data["conditions_indoor"] = 
                    (!preg_match("#\bOUTDOORS\b#i", $data["conditions_roof"]));
            }
        }
        */
    }

    protected function _getGameId($dateTime, $homeTeamAbbreviation)
    {
        $dt = new DateTime($dateTime);
        $dt->setTimeZone(new DateTimeZone("America/New_York")); 
        return ($dt->format("YmdH") . $homeTeamAbbreviation);
    }

    protected function _getTeamAbbreviation($city, $mascot)
    {
        $abbreviation = null;

        $map = [
            // AFC EAST
            "#BUFFALO_BILLS#"           => "BUF", 
            "#MIAMI_DOLPHINS#"          => "MIA", 
            "#NEW_ENGLAND_PATRIOTS#"    => "NE", 
            "#NEW_YORK_JETS#"           => "NYJ", 

            // AFC NORTH
            "#BALTIMORE_RAVENS#"        => "BAL", 
            "#CINCINNATI_BENGALS#"      => "CIN", 
            "#CLEVELAND_BROWNS#"        => "CLE", 
            "#PITTSBURGH_STEELERS#"     => "PIT", 

            // AFC SOUTH
            "#HOUSTON_TEXANS#"          => "HOU", 
            "#INDIANAPOLIS_COLTS#"      => "IND", 
            "#JACKSONVILLE_JAGUARS#"    => "JAX", 
            "#TENNESSEE_TITANS#"        => "TEN", 

            // AFC WEST
            "#DENVER_BRONCOS#"          => "DEN", 
            "#KANSAS_CITY_CHIEFS#"      => "KC", 
            "#CHARGERS#"                => "LAC", 
            "#OAKLAND_RAIDERS#"         => "OAK", 

            // NFC EAST
            "#DALLAS_COWBOYS#"          => "DAL", 
            "#PHILADELPHIA_EAGLES#"     => "PHI", 
            "#WASHINGTON_REDSKINS#"     => "WSH", 
            "#NEW_YORK_GIANTS#"         => "NYG", 

            // NFC NORTH
            "#CHICAGO_BEARS#"           => "CHI", 
            "#DETROIT_LIONS#"           => "DET", 
            "#GREEN_BAY_PACKERS#"       => "GB", 
            "#MINNESOTA_VIKINGS#"       => "MIN", 

            // NFC SOUTH
            "#ATLANTA_FALCONS#"         => "ATL", 
            "#CAROLINA_PANTHERS#"       => "CAR", 
            "#NEW_ORLEANS_SAINTS#"      => "NO", 
            "#TAMPA_BAY_BUCCANEERS#"    => "TB", 

            // NFC WEST
            "#RAMS#"                    => "LAR", 
            "#SAN_FRANCISCO_49ERS#"     => "SF", 
            "#ARIZONA_CARDINALS#"       => "ARI", 
            "#SEATTLE_SEAHAWKS#"        => "SEA"
        ];

        $key  = strtoupper((str_replace(" ", "_", trim("{$city}_{$mascot}"))));
        foreach ($map as $regex => $abbrev) {
            if (preg_match($regex, $key)) {
                $abbreviation = $abbrev;
                break;
            }
        }

        return $abbreviation;
    }
}

if (php_sapi_name() === "cli") {
    $scraper = new PFRScoresScraper();
    $scraper->start($argv);
}
