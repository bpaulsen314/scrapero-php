<?php
namespace Bpaulsen314\Scrapero;

use DOMDocument;
use DOMElement;
use DOMXpath;

use Bpaulsen314\Perfecto\ArrayHelper;
use Bpaulsen314\Perfecto\Object;
use Bpaulsen314\Perfecto\StringHelper;

class WebPage extends Object
{
    const DOWNLOAD_DIR = "/tmp/w3glue/scrapero/downloads";
    const DOCUMENT_CACHE_EXPIRY = 60;

    protected static $_magicCallMethods = [
        "getUri" => true
    ];

    protected static $_documentCache = [];

    protected $_downloaded = false;
    protected $_uri = null;

    public function __construct($uri)
    {
        $this->_uri = $uri;
    }

    public function getDocument()
    {
        $document = $this->_getDocumentFromCache();
        if (!$document) {
            $document = $this->_getDocumentFromDownload();
        }
        return $document;
    }

    public function getDownloadFilePath()
    {
        $dir = self::DOWNLOAD_DIR . "/" . get_current_user() . "/";
        $file = preg_replace("#^https?://#", "", $this->_uri);
        $file = preg_replace("#/#", "_", $file);
        return ($dir . $file . ".html");
    }

    public function getElementByXpath($query, $root = null)
    {
        $elements = $this->getElementsByXpath($query, $root);
        return array_pop($elements);
    }

    public function getElementsByXpath($query, $root = null)
    {
        $elements = [];

        $document = $this->getDocument();
        $xpath = new DOMXpath($document);
        if ($root) {
            $nodeList = $xpath->query($query, $root);
        } else {
            $nodeList = $xpath->query($query);
        }
        foreach ($nodeList as $node) {
            $elements[] = $node;
            if ($pop) {
                $elements = array_pop($elements);
                break;
            }
        }

        return $elements;
    }

    public function getTableByXpath($query, $options = [])
    {
        if (!is_array($options)) {
            $options = [];
        }

        $data = [];

        $aHelper = ArrayHelper::getInstance();
        $sHelper = StringHelper::getInstance();

        $optionsDefaults = [
            "cellFunction" => null,
            "columnFunction" => null,
            "columnQuery" => ".//thead//th",
            "rowFunction" => null,
            "rowQuery" => ".//tbody//tr"
        ];
        foreach ($optionsDefaults as $key => $value) {
            if (!isset($options[$key])) {
                $options[$key] = $value;
            }
        }

        $table = $this->getElementByXpath($query);
        if ($table) {
            $cols = [];
            $colElements = $this->getElementsByXpath($options["columnQuery"], $table);
            for ($i = 0; $i < count($colElements); $i++) {
                $col = $colElements[$i];;
                if (isset($options["columnFunction"])) {
                    $col = call_user_func($options["columnFunction"], $i, $col);
                }
                if ($col instanceof DOMElement) {
                    $col = $sHelper->camelNotate($col->textContent, true);
                }
                if ($col || $col === "") {
                    $cols[] = $col;
                }
            }

            $rows = [];
            $rowElements = $this->getElementsByXpath($options["rowQuery"], $table);
            for ($i = 0; $i < count($rowElements); $i++) {
                $row = $rowElements[$i];;
                if (isset($options["rowFunction"])) {
                    $row = call_user_func($options["rowFunction"], $i, $row);
                }
                if ($row instanceof DOMElement || is_array($row)) {
                    $rows[] = $row;
                }
            }

            foreach ($rows as $row) {
                $cells = [];
                if (is_array($row)) {
                    $cells = array_pop($row);
                    $row = array_pop($row);
                }
                $cellElements = $this->getElementsByXpath(".//td", $row);
                for ($i = 0; $i < count($cellElements); $i++) {
                    $cell = $cellElements[$i];;
                    $col = isset($cols[$i]) ? $cols[$i] : $i;
                    if (isset($options["cellFunction"])) {
                        $cell = call_user_func($options["cellFunction"], $col, $cell);
                    }
                    if (!is_array($cell) && ($cell || $cell === "")) {
                        $cell = [$col => $cell];
                    }
                    if ($cell) {
                        foreach ($cell as $key => $value) {
                            if ($value instanceof DOMElement) {
                                $value = $value->textContent;
                            }
                            $cells[$key] = $value;
                        }
                    }
                }
                if (count($cols) <= count($cells)) {
                    $data[] = $cells;
                }
            }
        }

        return $data;   
    }

    public function getTextByXpath($query, $root = null)
    {
        $texts = $this->getTextsByXpath($query, $root);
        return array_pop($texts);
    }

    public function getTextsByXpath($query, $root = null)
    {
        $texts = [];

        $elements = $this->getElementsByXpath($query, $root);
        foreach ($elements as $element) {
            $texts[] = $element->textContent;
        }
        
        return $texts;
    }

    protected function _getDocumentFromCache()
    {
        $document = null;

        $now = time();
        $expiredUris = [];
        foreach (self::$_documentCache as $uri => $cache) {
            if ($cache["lastAccess"] + self::DOCUMENT_CACHE_EXPIRY <= $now) {
                $expiredUris[] = $uri;
            }
        }

        foreach ($expiredUris as $uri) {
            unset(self::$_documentCache[$uri]);
        }

        if (isset(self::$_documentCache[$this->_uri])) {
            $document = self::$_documentCache[$this->_uri]["document"];
            self::$_documentCache[$this->_uri]["lastAccess"] = $now;
        }

        return $document;
    }

    protected function _getDocumentFromDownload()
    {
        $document = new DOMDocument();

        $filePath = $this->getDownloadFilePath();
        if (!$this->_downloaded || !file_exists($filePath)) {
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }
            file_put_contents($filePath, file_get_contents($this->_uri));
        }
        @$document->loadHTMLFile($filePath);

        self::$_documentCache[$this->_uri] = [
            "document" => $document,
            "lastAccess" => time()
        ];

        return $document;
    }
}
