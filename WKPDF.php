<?php

namespace WKPDF {

class Document implements \ArrayAccess
{

    /**
     * Path to the wkhtmltopdf binary. If left as null, WKPDF will attempt to
     * auto-detect on the first render.
     * @var string
     */
    public static $binaryPath = null;

    private static function autoDetectBinaryPath()
    {

        $ret = 'wkhtmltopdf';

        $os = strtolower(substr(php_uname('s'), 0, 3));

        if($os == 'win')
            $ret .= '.exe';

        else
        {

            $ret .= '-';

            if($os == 'dar')
                $ret .= (stripos(`machine`, 'ppc') !== false) ? 'ppc' : 'i386';

            else
                $ret .= (stripos(php_uname('m'), '64') !== false) ? 'amd64' : 'i386';

            if(preg_match('`((?:\/[-\w]+)+)`', `type $ret`, $r))
                $ret = $r[1];
        }

        return $ret;
    }

    /**
     * Create a new Document instance from an HTML string.
     * @param string $html The HTML document to be converted to PDF.
     * @return Document
     */
    public static function fromString($html)
    {
        return self::fromStringRef($html);
    }

    /**
     * Create a new Document instance from an HTML string, passed by reference.
     * @param string $html The HTML document to be converted to PDF.
     * @return Document
     */
    public static function fromStringRef(&$html)
    {
        $ret = new self;
        return $ret->sourceRef($html);
    }

    /**
     * Create a new Document instance from an HTML file.
     * @param string $path Path of the HTML document to be converted to PDF.
     * @return Document
     */
    public static function fromFile($path)
    {
        $ret = new self;
        return $ret->sourceFile($path);
    }

    private static function cleanSwitchName($switchName) {
        return preg_replace('`[A-Z]`e', '"-" . strtolower("$0")', preg_replace('`[^-a-zA-Z]`', '', $switchName));
    }

    public static function makeDataUri($data, $type = 'text/html', $charset = null, $base64 = true)
    {
        return sprintf(
            'data:%s%s%s,%s',
            $type,
            $charset ? ";charset=$charset" : '',
            $base64 ? ';base64' : '',
            $base64 ? base64_encode($data) : rawurlencode($data)
            );
    }

    private $switches = array(
        'encoding' => 'utf-8',
        'dpi' => 300,
        'disable-javascript' => true,
        'no-outline' => true,
        'margin-top' => '18mm',
        'margin-bottom' => '18mm',
        'margin-left' => '18mm',
        'margin-right' => '18mm'
    );
    
    private $sourceFile = null;
    private $sourceData = null;
    private $headerAndFooterReplacements = array();

    private $result = null;

    private $headerAndFooter = array();

    public function __construct($source = null)
    {
        if(is_string($source) && strlen($source))
        {
            if($source{0} === '<')
                $this->setSourceRef($source);

            elseif(is_file($source))
                $this->setSourceFile($source);
        }
    }

    private function clearResult()
    {
        $this->result = null;
        return $this;
    }

    /**
     * Set a switch.
     * 
     * @param string $switch
     * @param mixed $value
     * @return Document
     */
    public function set($switch, $value)
    {
        return $this->offsetSet($switch, $value);
    }

    public function offsetExists($offset)
    {
        return isset($this->switches[self::cleanSwitchName($offset)]);
    }

    public function offsetGet($offset)
    {
        $offset = self::cleanSwitchName($offset);

        return isset($this->switches[$offset])
            ? $this->switches[$offset]
            : null;
    }

    public function offsetSet($offset, $value)
    {
        $this->switches[self::cleanSwitchName($offset)] = $value;
        return $this->clearResult();
    }

    public function offsetUnset($offset)
    {
        $offset = self::cleanSwitchName($offset);

        if(isset($this->switches[$offset]))
        {
            unset($this->switches[$offset]);
            $this->clearResult();
        }
    }

    public function source($html)
    {
        return $this->sourceRef($html);
    }

    public function sourceRef(&$html)
    {
        $this->sourceFile = '-';
        $this->sourceData =& $html;

        return $this->clearResult();
    }

    public function sourceFile($path)
    {
        if(!is_file($path))
            throw new Exception(sprintf("File '%s' not found.", $path));

        $this->sourceFile = realpath($path);

        if(!is_readable($this->sourceFile))
            throw new Exception(sprintf("Cannot read file '%s'.", $this->sourceFile));

        unset($this->sourceData);

        return $this->clearResult();
    }

    private function render()
    {
        if($this->result === null)
        {
            if(self::$binaryPath === null)
                self::$binaryPath = self::autoDetectBinaryPath();

            if(!is_executable(self::$binaryPath))
                throw new Exception(sprintf("File '%s' is not executable.", self::$binaryPath));

            $cmdParts = array(self::$binaryPath);

            foreach($this->switches as $k => $i)
                if($i !== null && $i !== false)
                {
                    $cmdParts[] = '--' . $k;
                    if($i !== true)
                        $cmdParts[] = escapeshellarg(strval($i));
                }

            foreach($this->headerAndFooterReplacements as $k => $i)
            {
                $cmdParts[] = '--replace';
                $cmdParts[] = escapeshellarg($k);
                $cmdParts[] = escapeshellarg($i);
            }

            $cmdParts[] = $this->sourceFile;
            $cmdParts[] = '-';

            $pipes = array();

            $proc = proc_open(implode(' ', $cmdParts), array(
                array('pipe', 'r'),
                array('pipe', 'w'),
                array('pipe', 'w')
            ), $pipes);

            if(isset($this->sourceData) && $this->sourceData)
                fwrite($pipes[0], $this->sourceData);

            fclose($pipes[0]);

            array_shift($pipes);

            $results = array();

            foreach($pipes as $pipe)
            {
                $results[] = '';
                stream_set_blocking($pipe, 0);
            }

            $delay = 10000;

            do
            {

                $gotBytes = false;

                foreach($pipes as $k => $pipe)
                    if(!feof($pipe) && strlen($buffer = fgets($pipe, 1024)))
                    {
                        $gotBytes = true;
                        $results[$k] .= $buffer;
                    }

                if(!$gotBytes)
                {
                    usleep($delay);
                    if($delay < 160000)
                        $delay *= 2;
                }
                else
                    $delay = 10000;

            }
            while(count(array_filter(array_map('feof', $pipes))) < 2);

            array_map('fclose', $pipes);

            $exitCode = proc_close($proc);

            if($exitCode)
                throw new BinaryException(
                    'wkhtmltopdf exited with code ' . $exitCode,
                    $exitCode,
                    $results[1]
                    );

            $this->result = $results[0];
            
        }
        
        return $this;
    }

    public function __toString()
    {
        return $this->render()->result;
    }

    /**
     * Set margins using CSS shorthand. Numeric values will be treated as
     * millimeters. Boolean values will leave the value as-is.
     *
     * @staticvar array $sides
     * @param float|integer|string|boolean $top
     * @param float|integer|string|boolean $right If omitted, will use the value
     * of <b>$top</b>.
     * @param float|integer|string|boolean $bottom If omitted, will use the
     * value of <b>$top</b>.
     * @param float|integer|string|boolean $left If omitted, will use the value
     * of <b>$right</b>.
     * @return Document The Document instance, for chaining.
     */
    public function margins($top, $right = null, $bottom = null, $left = null)
    {
        static $sides = array('top', 'right', 'bottom', 'left');

        if(is_string($top) && strpos($top, ' ') !== false)
            $top = preg_split('`\s+`', $top);

        if(is_array($top))
            return call_user_func_array(array($this, __METHOD__), $top);

        if($right === null)
            $right = $top;

        if($bottom === null)
            $bottom = $top;

        if($left === null)
            $left = $right;

        foreach($sides as $side)

            if(is_numeric($$side))
                $this['margin-' . $side] = $$side . 'mm';

            else if(is_string($$side))
                $this['margin-' . $side] = $$side;

        return $this;

    }

    public function outline($include = true)
    {
        $this['no-outline'] = !$include;
        $this['outline'] = !!$include;

        return $this;
    }

    public function background($include = true)
    {
        $this['no-background'] = !$include;
        $this['background'] = !!$include;

        return $this;
    }

    public function images($include = true)
    {
        $this['no-images'] = !$include;
        $this['images'] = !!$include;

        return $this;
    }

    public function cssString($css, $charset = 'utf-8', $useTempFile = false)
    {
        $this['user-style-sheet'] = $useTempFile
            ? TempFile::create($css, 'css')
            : self::makeDataUri($css, 'text/css', $charset);

        return $this;
    }

    public function cssFile($path)
    {
        if(!is_file($path) || !is_readable($path))
            throw new Exception("Unable to read file '$path'.");

        $this['user-style-sheet'] = realpath($path);
    }

    public function orientation($orientation = 'Portrait')
    {
        $this['orientation'] = (strlen($orientation) && strtolower($orientation{0}) == 'p')
            ? 'Portrait'
            : 'Landscape';

        return $this;
    }

    public function pageSize($pageSize = 'A4')
    {
        $this['page-size'] = $pageSize;

        return $this;
    }

    public function title($title = false)
    {
        $this['title'] = $title;

        return $this;
    }

    public function replace($name, $value)
    {
        $a =& $this->headerAndFooterReplacements;

        if($value === null && isset($a[$name]))
            unset($a[$name]);

        else
            $a[$name] = $value;

        return $this->clearResult();
    }

    private function getHeaderOrFooter($which)
    {
        if(!isset($this->headerAndFooter[$which]))
            return $this->headerAndFooter[$which] = new HeaderOrFooter($this, explode(' ', $which));

        return $this->headerAndFooter[$which];
    }



    /**
     * Methods to modify the PDF page headers
     * @return HeaderOrFooter
     */
    public function header()
    {
        return $this->getHeaderOrFooter('header');
    }

    /**
     * Methods to modify the PDF page footers
     * @return HeaderOrFooter
     */
    public function footer()
    {
        return $this->getHeaderOrFooter('footer');
    }

    /**
     * Methods to modify the PDF page headers and footers (simultaneously)
     * @return HeaderOrFooter
     */
    public function headerAndFooter()
    {
        return $this->getHeaderOrFooter('header footer');
    }

    public function serve($asAttachment = false, $fileName = null)
    {
        header('Content-Type: application/pdf');

        $this->render();

        if(function_exists('gzencode')
            && isset($_SERVER['ACCEPT_ENCODING'])
            && preg_match('`\bgzip\b`i', $_SERVER['ACCEPT_ENCODING']))
        {
            header('Content-Encoding: gzip');
            $response = gzencode($this);
        }
        else
            $response =& $this->result;

        header(sprintf('Content-Disposition: %s; filename="%s"',
            $asAttachment ? 'attachment' : 'inline',
            $fileName ?: 'Document.pdf')
            );

        die($response);
    }

    public function save($path)
    {
        if(file_put_contents($path, $this) === false)
            throw new Exception("Unable to write to file '$this'.");

        return $this;
    }

}

class HeaderOrFooter
{

    /**
     * @var Document
     */
    private $parent;

    private $headerOrFooter;

    private $htmlController = null;

    public function __construct(Document $parent, Array $headerOrFooter)
    {
        $this->parent = $parent;
        $this->headerOrFooter = $headerOrFooter;
    }

    private function setSwitch($switch, $value)
    {
        foreach($this->headerOrFooter as $i)
            $this->parent[$i . '-' . $switch] = $value;

        return $this;
    }

    /**
     * @return Document
     */
    public function end()
    {
        return $this->parent;
    }

    public function text($left = null, $center = null, $right = null)
    {
        static $alignments = array('left', 'center', 'right');

        foreach($alignments as $alignment)
            $this->$alignment($$alignment);

        return $this;
    }

    public function left($text = null)
    {
        return $this->setSwitch('left', $text);
    }

    public function center($text = null)
    {
        return $this->setSwitch('center', $text);
    }

    public function right($text = null)
    {
        return $this->setSwitch('right', $text);
    }

    public function line($include = true)
    {
        return $this->setSwitch('line', !!$include);
    }

    public function spacing($millimeters = 0)
    {
        return $this->setSwitch('spacing', $millimeters);
    }

    public function font($name = 'Arial', $size = 12)
    {
        static $props = array('name', 'size');

        foreach($props as $prop)
            if($$prop !== null)
                $this->setSwitch('font-' . $prop, $$prop);

        return $this;
    }

    public function htmlString($html, $noTempfile = false, $charset = null)
    {
        return $this->setSwitch('html', $noTempfile
            ? Document::makeDataUri($html . '<!--<![CDATA[', 'text/html', $charset)
            : TempFile::create($html)
            );
    }

    public function htmlFile($path)
    {
        if(!is_file($path) || !is_readable($path))
            throw new Exception("Can't read file '$path'.");
        
        return $this->setSwitch('html', realpath($path));
    }

    /**
     * @return HeaderOrFooterHTMLController
     */
    public function html()
    {
        if($this->htmlController === null)
            $this->htmlController = new HeaderOrFooterHTMLController($this, $this->headerOrFooter);

        return $this->htmlController;
    }
}

class Exception extends \Exception { }

class BinaryException extends Exception
{
    public function __construct($message, $code, $error)
    {
        parent::__construct($message, $code);
        $this->error = $error;
    }
    public $error;
}

class TempFile
{

    public static $tempDir = null;

    private static $pool = array();

    /**
     *
     * @param string $contents
     * @param string $extension
     * @return TempFile
     */
    public static function create($contents, $extension = 'html')
    {
        $fileName = sha1($contents) . '.' . $extension;

        return isset(self::$pool[$fileName])
            ? self::$pool[$fileName]
            : new self($contents, $fileName);
    }
    
    private $contents;
    private $fileName;
    private $path = null;

    private function __construct($contents, $fileName)
    {
        self::$pool[$fileName] = $this;

        $this->contents =& $contents;
        $this->fileName = $fileName;

    }

    public function __destruct()
    {
        if($this->path)
            @unlink($this->path);
    }

    public function path()
    {
        if(!$this->path)
        {
            if(self::$tempDir === null)
                foreach(array(sys_get_temp_dir(), dirname(__FILE__)) as $i)
                    if(is_writable($dir = realpath($i) . DIRECTORY_SEPARATOR))
                    {
                        self::$tempDir = $dir;
                        break;
                    }

            if(self::$tempDir === null)
                throw new Exception("Unable to write to temp dir.");

            if(substr(self::$tempDir, -1) != DIRECTORY_SEPARATOR)
                self::$tempDir .= DIRECTORY_SEPARATOR;

            file_put_contents($this->path = self::$tempDir . $this->fileName, $this->contents);
        }

        return $this->path;
    }

    public function __toString()
    {
        return $this->path();
    }
}

class HeaderOrFooterHTML
{
    private static $template = '<!doctype html>
<html>
<head>
    <meta charset="%s"/>
    <script>
    function doSubstitutes() {
        var html = document.body.innerHTML;
        document.location.search.substr(1).split("&").forEach(function(x) {
            x = x.split("=", 2).map(decodeURIComponent);
            html = html.replace(new RegExp("\\\\[" + x[0] + "]", "g"), x[1]);
        });
        document.body.innerHTML = html;
    }
    </script>
    <style>%s</style>
</head>
<body onload="doSubstitutes()">
%s
</body>
</html>
';

    private $charset = 'utf-8';
    private $body = null;
    private $css = null;

    public function charset($charset)
    {
        $this->charset = $charset;
    }
    public function body($html)
    {
        $this->body = $html;
    }

    public function css($css)
    {
        $this->css = $css;
    }

    private function render()
    {
        return sprintf(
            self::$template,
            $this->charset,
            $this->css,
            $this->body
            );
    }

    public function __toString()
    {
        return (string) TempFile::create($this->render());
    }
}

class HeaderOrFooterHTMLController
{
    private $parent;
    private $headerOrFooter;

    public function __construct(HeaderOrFooter $parent, Array $headerOrFooter)
    {
        $this->parent = $parent;
        $this->headerOrFooter = $headerOrFooter;
    }

    private function setProperty($name, $value)
    {
        $document = $this->parent->end();

        foreach($this->headerOrFooter as $headerOrFooter)
        {
            $key = $headerOrFooter . '-html';

            if($document[$key] instanceof HeaderOrFooterHTML)
                $html = $document[$key];

            else
                $html = $document[$key] = new HeaderOrFooterHTML;

            $html->$name($value);
        }

        return $this;
    }

    /**
     *
     * @param string $html
     * @return HeaderOrFooterHTMLController
     */
    public function body($html)
    {
        return $this->setProperty('body', $html);
    }

    /**
     *
     * @param string $cssRules
     * @return HeaderOrFooterHTMLController
     */
    public function css($cssRules)
    {
        return $this->setProperty('css', $cssRules);
    }

    /**
     *
     * @param string $charset
     * @return HeaderOrFooterHTMLController
     */
    public function charset($charset = 'utf-8')
    {
        return $this->setProperty('charset', $charset);
    }

    /**
     *
     * @return HeaderOrFooter
     */
    public function end()
    {
        return $this->parent;
    }
}

}

namespace
{
    class WKPDFDocument extends \WKPDF\Document { }
}
