<?php
namespace Bpaulsen314\Scrapero;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMXpath;
use Exception;

use Bpaulsen314\Perfecto\ArrayHelper;
use Bpaulsen314\Perfecto\Object;
use Bpaulsen314\Perfecto\StringHelper;

class WebPage extends Object
{
    const DOWNLOAD_DIR = "/tmp/bpaulsen314/scrapero/downloads";
    const DOCUMENT_CACHE_EXPIRY = 60;

    protected static $_magicCallMethods = [
        "getUri" => true
    ];

    protected static $_documentCache = [];

    protected static $_useBrowser = false;

    protected $_downloaded = false;
    protected $_uri = null;

    public static function setUseBrowser($useBrowser)
    {
        static::$_useBrowser = $useBrowser;
    }

    public function __construct($uri)
    {
        $this->_uri = $uri;
    }

    public function getAttributeByXpath($name, $query, $root = null)
    {
        $attributes = $this->getAttributesByXpath($name, $query, $root);
        return array_pop($attributes);
    }

    public function getAttributesByXpath($name, $query, $root = null)
    {
        $attributes = [];

        $elements = $this->getElementsByXpath($query, $root);
        foreach ($elements as $element) {
            $attributes[] = $element->getAttribute($name);
        }
        
        return $attributes;
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
                $col = $colElements[$i];
                if (isset($options["columnFunction"])) {
                    $col = call_user_func($options["columnFunction"], $i, $col);
                }
                if ($col instanceof DOMElement) {
                    $col = $sHelper->camelNotate($col->textContent, true);
                }
                if (!is_array($col)) {
                    $col = [$col];
                }
                foreach ($col as $c) {
                    $cols[] = $c;
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
                $cellElements = $this->getElementsByXpath(".//td | .//th", $row);
                for ($i = 0; $i < count($cellElements); $i++) {
                    $cell = $cellElements[$i];
                    $col = isset($cols[$i]) ? $cols[$i] : $i;
                    if (!$col) continue;
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
                $data[] = $cells;
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

    public function uncomment($query, $root = null)
    {
        $element = $this->getElementByXpath($query, $root);
        if ($element instanceof DOMComment) {
            $doc = new DOMDocument();
            $doc->loadHtml($element->textContent);
            $doc->normalizeDocument();
            $xpath = new DOMXPath($doc);
            $nodes = $xpath->query("//body");
            foreach ($nodes as $node) {
                foreach ($node->childNodes as $cn) {
                    $n = $this->getDocument()->importNode($cn, true);
                    $element->parentNode->appendChild($n);
                }
            }
        } else {
            $error = [
                "No comment block found using xpath expression:", $query
            ];
            throw new Exception(implode(" ", $error));
        }
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
            $contents = [];
            if (static::$_useBrowser) {
                // $client = PhantomJsClient::getInstance();
                // $client->getEngine()->setPath(static::$_usePhantomJs);
                // $request = $client->getMessageFactory()->createRequest(
                //     $this->_uri, "GET"
                // );
                // $response = $client->getMessageFactory()->createResponse();
                // $client->send($request, $response);
                // $contents = $response->getContent();
                $contents = [];
            } else {
                $contents = file_get_contents($this->_uri);
            }
            file_put_contents($filePath, $contents);
        }
        @$document->loadHTMLFile($filePath);

        self::$_documentCache[$this->_uri] = [
            "document" => $document,
            "lastAccess" => time()
        ];

        return $document;
    }
}
