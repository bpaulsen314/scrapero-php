<?php
namespace Bpaulsen314\Scrapero;

use Bpaulsen314\Scripto\Script;

class Scraper extends Script
{
    protected function _run()
    {
        // ini_set("display_errors", "1");

        WebPage::setUseBrowser($this->_config["useBrowser"]);

        $seed = new WebPage($this->_config["seed"]);

        $this->log('Crawling "' . $seed->getUri() . '" ... ', false);

        $pages = $this->_crawl($seed);

        $this->log("DONE!");
        $this->log();

        $data = [];

        $counter = 1;
        foreach ($pages as $page) {
            if (is_string($page)) {
                $page = new WebPage($page);
            }

            $this->log("Scraping page {$counter} of " . count($pages) . " -- ", false);
            $this->log($page->getUri() . " ... ", false);

            $record = $this->_scrape($page);
            if ($record) {
                $data[$page->getUri()] = $record;
            }

            $this->log("DONE!");

            $counter++;
        }

        $data = $this->_finalize($data);

        $filePath = $this->_getOutputFilePath();
        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        if (preg_match("#\bCSV\b#i", $this->_arguments["output_format"])) {
            $fp = fopen($filePath, "w");
            $first = true;
            foreach ($data as $source => $record) {
                if ($first) {
                    fputcsv($fp, array_keys($record));
                    $first = false;
                }
                fputcsv($fp, array_values($record));
            }
            fclose($fp);
        } else {
            file_put_contents($filePath, json_encode($data));
        }
    }

    protected function _crawl($page, $options = [])
    {
        return [$page];
    }

    protected function _scrape($page, $options = [])
    {
        return [];
    }

    protected function _finalize($data, $options = [])
    {
        return $data;
    }

    protected function _getConfigDefaults()
    {
        $defaults = parent::_getConfigDefaults();
        $default_output_dir = "/tmp/bpaulsen314/scrapero/output/" .
            get_current_user() . "/";
        $defaults["arguments"]["output_dir"] = [
            "short" => "o",
            "description" => "Location for scraped data.",
            "default_value" => $default_output_dir
        ];
        $defaults["arguments"]["output_format"] = [
            "short" => "f",
            "description" => "File format of scraped data.",
            "default_value" => "json"
        ];
        return $defaults;
    }

    protected function _getOutputFilePath()
    {
        $dir = $this->_arguments["output_dir"];
        $file = str_replace("\\", "_", get_class($this));
        $file .= date(".Ymd_His");
        return ($dir . $file . "." . $this->_arguments["output_format"]);
    }
}
