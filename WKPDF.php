<?php

/**
 * WKPDF
 * by Neil E. Pearson
 *
 * PHP sugar for the wkhtmltopdf shell utility by antialize.
 *
 * wkhtmltopdf home: http://code.google.com/p/wkhtmltopdf/
 * wkhtmltopdf code: https://github.com/antialize/wkhtmltopdf
 *
 * @package WKPDF
 * @author Neil E. Pearson
 * @copyright Copyright 2012 Neil E. Pearson
 * @version 0.1
 * @license http://www.apache.org/licenses/LICENSE-2.0 Licensed under the Apache License, Version 2.0
 */

/**
 * Namespace for WKPDF classes
 */
namespace WKPDF {

/**
 * Represents a single PDF document
 */
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
     * If called statically, create a new Document instance from an HTML string.
     * If bound to an instance, replace the document's source with an HTML string.
     * @param string $html The HTML document to be converted to PDF.
     * @return Document
     * @static
     */
    public function fromString($html)
    {
        return isset($this)
            ? $this->fromStringRef($html)
            : self::fromStringRef($html);
    }

    /**
     * If called statically, create a new Document instance from an HTML string,
     * passed by reference. If bound to an instance, replace the document's
     * source with an HTML string reference.
     * @param string $html The HTML document to be converted to PDF.
     * @return Document
     * @static
     */
    public function fromStringRef(&$html)
    {
        $ret = isset($this) ? $this : new self;

        $ret->sourceFile = '-';
        $ret->sourceData =& $html;

        return $ret->clearResult();
    }

    /**
     * If called statically, create a new Document instance from an HTML file.
     * If bound to an instance, replace the document's source with an HTML file.
     * @param string $path Path of the HTML document to be converted to PDF.
     * @return Document
     * @static
     */
    public function fromFile($path)
    {
        $ret = isset($this) ? $this : new self;

        if(!is_file($path))
            throw new Exception(sprintf("File '%s' not found.", $path));

        $ret->sourceFile = realpath($path);

        if(!is_readable($ret->sourceFile))
            throw new Exception(sprintf("Cannot read file '%s'.", $ret->sourceFile));

        unset($ret->sourceData);

        return $ret->clearResult();
    }

    private static function cleanSwitchName($switchName) {
        return preg_replace('`[A-Z]`e', '"-" . strtolower("$0")', preg_replace('`[^-a-zA-Z]`', '', $switchName));
    }

    /**
     * Helper method used by other classes to prepare data URIs.
     * @param string $contents Contents of the data URI.
     * @param string|boolean $type Content type, or <b>false</b> to omit.
     * @param string|boolean $charset Content character encoding, or <b>false</b>
     * to omit.
     * @param boolean $base64 Encode as base64
     * @return string The complete data uri
     */
    public static function makeDataUri($contents, $type = 'text/html', $charset = false, $base64 = true)
    {
        return sprintf(
            'data:%s%s%s,%s',
            $type,
            $charset ? ";charset=$charset" : '',
            $base64 ? ';base64' : '',
            $base64 ? base64_encode($contents) : rawurlencode($contents)
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

    /**
     * Create a new Document, with an optional source.
     * @param string|null $source Path to an HTML document, or an HTML string.
     * WKPDF will attempt to auto-detect which is supplied.
     */
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

    /**
     * @return Document
     */
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

    /**
     * Part of ArrayAccess implementation. Determine if a switch has been
     * specified. Use <b>isset()</b> instead.
     * @param string $offset The switch to test.
     * @return boolean True if the switch has been specified.
     */
    public function offsetExists($offset)
    {
        return isset($this->switches[self::cleanSwitchName($offset)]);
    }

    /**
     * Part of ArrayAccess implementation. Get the value of a switch. Use
     * array accessors <b>[ ]</b> instead.
     * @param string $offset The switch to query.
     * @return mixed Value of the given switch, or <b>null</b> if it doesn't
     * exist.
     */
    public function offsetGet($offset)
    {
        $offset = self::cleanSwitchName($offset);

        return isset($this->switches[$offset])
            ? $this->switches[$offset]
            : null;
    }

    /**
     * Part of ArrayAccess implementation. Set a switch. Use array accessors
     * <b>[ ]</b> instead.
     * @param string $offset The switch to set.
     * @param mixed $value Value of the switch. For value-less switches, use
     * <b>true</b> to include or <b>false</b> to exclude.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function offsetSet($offset, $value)
    {
        $this->switches[self::cleanSwitchName($offset)] = $value;
        return $this->clearResult();
    }

    /**
     * Part of ArrayAccess implementation. Remove a switch. Use <b>unset()</b>
     * instead.
     * @param string $offset The switch to remove.
     */
    public function offsetUnset($offset)
    {
        $offset = self::cleanSwitchName($offset);

        if(isset($this->switches[$offset]))
        {
            unset($this->switches[$offset]);
            $this->clearResult();
        }
    }

    /**
     * Render the document.
     * 
     * @return Document
     * @throws Exception
     * @throws BinaryException
     */
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

    /**
     * Get the document's PDF data.
     * @return string
     */
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
     * @return Document The instance on which the method was called, for chaining.
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

    /**
     * Include or exclude an outline.
     * @param boolean $include <b>True</b> to include an outline, or <b>false</b>
     * not to include an outline.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function outline($include = true)
    {
        $this['no-outline'] = !$include;
        $this['outline'] = !!$include;

        return $this;
    }

    /**
     * Enable or disable rendering of background images.
     * @param boolean $include <b>True</b> to include background images, or
     * <b>false</b> to exclude background images.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function background($include = true)
    {
        $this['no-background'] = !$include;
        $this['background'] = !!$include;

        return $this;
    }

    /**
     * Enable or disable rendering of images.
     * @param boolean $include <b>True</b> to include images, or <b>false</b> to
     * exclude background images.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function images($include = true)
    {
        $this['no-images'] = !$include;
        $this['images'] = !!$include;

        return $this;
    }

    /**
     * Specify additional CSS directly from a string.
     * @param string $css The CSS rules to be applied to the document.
     * @param string $charset Character set of the CSS being applied.
     * @param boolean $useTempFile Use a temp file instead of a data uri. May
     * be helpful for large amounts of CSS.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function cssString($css, $charset = 'utf-8', $useTempFile = false)
    {
        $this['user-style-sheet'] = $useTempFile
            ? TempFile::create($css, 'css')
            : self::makeDataUri($css, 'text/css', $charset);

        return $this;
    }

    /**
     * Specify an additional CSS file.
     * @param string $path Path of the CSS file.
     * @throws Exception
     */
    public function cssFile($path)
    {
        if(!is_file($path) || !is_readable($path))
            throw new Exception("Unable to read file '$path'.");

        $this['user-style-sheet'] = realpath($path);
    }

    /**
     * Enable or disable Webkit's smart shrinking.
     * @param boolean $use <b>True</b> to use smart shrinking, or <b>false</b>
     * to not use smart shrinking.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function smartShrinking($use = true)
    {
        $this['disable-smart-shrinking'] = !$use;
        $this['enable-smart-shrinking'] = !!$use;

        return $this;
    }

    /**
     * Enable or disable inclusion of links to external URIs.
     * @param boolean $use <b>True</b> to include external links, or <b>false</b>
     * to exclude external links.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function externalLinks($use = true)
    {
        $this['disable-external-links'] = !$use;
        $this['enable-external-links'] = !!$use;

        return $this;
    }

    /**
     * Set the page orientation.
     * @param string $orientation <b>Portrait</b> or <b>Landscape</b>
     * @return Document The instance on which the method was called, for chaining.
     */
    public function orientation($orientation = 'Portrait')
    {
        $this['orientation'] = (strlen($orientation) && strtolower($orientation{0}) == 'p')
            ? 'Portrait'
            : 'Landscape';

        return $this;
    }

    /**
     * Set the page size.
     * @param string $pageSize Page size (A4, Letter etc).
     * @return Document The instance on which the method was called, for chaining.
     */
    public function pageSize($pageSize = 'A4')
    {
        $this['page-size'] = $pageSize;

        return $this;
    }

    /**
     * Change the DPI explicitly (this has no effect on X11 based systems).
     * @param integer $resolution DPI to use.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function dpi($resolution = null)
    {
        $this['dpi'] = intval($resolution) ?: 300;

        return $this;
    }

    /**
     * Set the document title.
     * @param string $title New document title.
     * @return Document The instance on which the method was called, for chaining.
     */
    public function title($title = false)
    {
        $this['title'] = $title;

        return $this;
    }

    /**
     * Add extra header/footer replacement values.
     * @param string $name String that will be replaced, if found inside square
     * brackets <b>[ ]</b>.
     * @param string $value Replacement string.
     * @return Document The instance on which the method was called, for chaining.
     */
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

    /**
     * Output a <b>Content-Type: application/pdf</b> header, followed by the
     * rendered PDF. If gzip is supported at both ends, also outputs a
     * <b>Content-Encoding: gzip</b> header and compresses the PDF.
     * @param boolean $asAttachment <b>True</b> to serve as an attachement (aka
     * forced download), or <b>false</b> to serve inline (standard).
     * @param string $fileName When serving as an attachment (forced download),
     * this filename will be used.
     */
    public function serve($asAttachment = false, $fileName = 'Document.pdf')
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
            $fileName)
            );

        die($response);
    }

    /**
     * Save the PDF to a file.
     * @param string $path Path of the file to which the PDF is to be written.
     * @return Document The instance on which the method was called, for chaining.
     * @throws Exception
     */
    public function save($path)
    {
        if(file_put_contents($path, $this) === false)
            throw new Exception("Unable to write to file '$this'.");

        return $this;
    }

}

/**
 * Provides methods for modifying each page's header and/or footer.
 */
class HeaderOrFooter
{

    /**
     * @var Document
     */
    private $parent;

    /**
     * @var array
     */
    private $headerOrFooter;

    /**
     * @var HeaderOrFooterHTMLController
     */
    private $htmlController = null;

    /**
     * Shouldn't be used directly. Call a <b>Document</b> instance's <b>header()</b>,
     * <b>footer()</b>, or <b>headerAndFooter()</b> method instead.
     * @param Document $parent
     * @param array $headerOrFooter
     */
    public function __construct(Document $parent, array $headerOrFooter)
    {
        $this->parent = $parent;
        $this->headerOrFooter = $headerOrFooter;
    }

    /**
     *
     * @param string $switch
     * @param mixed $value
     * @return HeaderOrFooter The instance on which the method was called, for
     * chaining.
     */
    private function setSwitch($switch, $value)
    {
        foreach($this->headerOrFooter as $i)
            $this->parent[$i . '-' . $switch] = $value;

        return $this;
    }

    /**
     * End a chain of method calls by returning the parent Document instance.
     * @return Document The parent Document instance.
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
