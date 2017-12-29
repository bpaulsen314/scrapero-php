<?php
namespace W3glue\Scrapero;

use W3glue\Scripto\Script;

class Scraper extends Script
{
    protected function _run()
    {
        ini_set("display_errors", "1");

        $seedPage = new WebPage($this->_config["seed"]);

        $this->log('Crawling "' . $seedPage->getUri() . '" ... ', false);

        $pages = $this->_crawl($seedPage);

        $this->log("DONE!");
        $this->log();

        $data = [];

        $counter = 1;
        foreach ($pages as $page) {
            if (is_string($page)) {
                $page = new WebPage($page);
            }

            $this->log("Scraping page {$counter} of " . count($pages) . " -- ", false);
            $this->log($page->getUri() . " ... ", true);
            $this->logIndent();

            $record = $this->_scrape($page);
            if ($record) {
                $data[] = $record;
            }

            $this->logUnindent();
            $this->log("DONE!");

            $counter++;
        }
    }

    protected function _crawl($page)
    {
        return [$page];
    }

    protected function _scrape($page)
    {
    }

    protected function _getConfigDefaults()
    {
        $defaults = parent::_getConfigDefaults();
        return $defaults;
    }
}
