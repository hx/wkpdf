<?php

class WKPDF implements ArrayAccess
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
     * Create a new WKPDF instance from an HTML string.
     * @param string $html The HTML document to be converted to PDF.
     * @return WKPDF
     */
    public static function fromString($html)
    {
        $ret = new self;
        return $ret->setSourceRef($html);
    }

    /**
     * Create a new WKPDF instance from an HTML string, passed by reference.
     * @param string $html The HTML document to be converted to PDF.
     * @return WKPDF
     */
    public static function fromStringReg(&$html)
    {
        $ret = new self;
        return $ret->setSourceRef($html);
    }

    /**
     * Create a new WKPDF instance from an HTML file.
     * @param string $path Path of the HTML document to be converted to PDF.
     * @return WKPDF
     */
    public static function fromFile($path)
    {
        $ret = new self;
        return $ret->setSourceFile($path);
    }

    private static function cleanSwitchName($switchName) {
        return preg_replace('`[A-Z]`e', '"-" . strtolower("$0")', preg_replace('`[^-a-zA-Z]`', '', $switchName));
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

    public function setSource($html)
    {
        return $this->setSourceRef($html);
    }

    public function setSourceRef(&$html)
    {
        $this->sourceFile = '-';
        $this->sourceData =& $html;

        return $this->clearResult();
    }

    public function setSourceFile($path)
    {
        if(!is_file($path))
            throw new WKPDFException(sprintf("File '%s' not found.", $path));

        $this->sourceFile = realpath($path);

        if(!is_readable($this->sourceFile))
            throw new WKPDFException(sprintf("Cannot read file '%s'.", $this->sourceFile));

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
                throw new WKPDFException(sprintf("File '%s' is not executable.", self::$binaryPath));

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
                throw new WKPDFTOHTMLException(
                    'wkhtmltopdf exited with code ' . $exitCode,
                    $exitCode,
                    $results[1]
                    );

            $this->result = $results[0];
            
        }
        
        return $this;
    }

    public function result()
    {
        return $this->render()->result;
    }

    public function setMargins($top = null, $right = null, $bottom = null, $left = null)
    {
        static $sides = array('top', 'right', 'bottom', 'left');

        foreach($sides as $side)

            if(is_numeric($$side))
                $this['margin-' . $side] = $$side . 'mm';

            else if(is_string($$side))
                $this['margin-' . $side] = $$side;

        return $this;

    }

    public function setOutline($include = true)
    {
        $this['no-outline'] = !$include;
        $this['outline'] = !!$include;

        return $this;
    }

    public function setOrientation($orientation = 'Portrait')
    {
        $this['orientation'] = (strlen($orientation) && strtolower($orientation{0}) == 'p')
            ? 'Portrait'
            : 'Landscape';

        return $this;
    }

    public function setPageSize($pageSize = 'A4')
    {
        $this['page-size'] = $pageSize;

        return $this;
    }

    public function setTitle($title = false)
    {
        $this['title'] = $title;

        return $this;
    }

    public function setReplace($name, $value)
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
            return $this->headerAndFooter[$which] = new WKDFHeaderOrFooter($this, $which);

        return $this->headerAndFooter[$which];
    }

    /**
     * Methods to modify the PDF page headers
     * @return WKPDFHeaderOrFooter
     */
    public function header()
    {
        return $this->getHeaderOrFooter('header');
    }

    /**
     * Methods to modify the PDF page footers
     * @return WKPDFHeaderOrFooter
     */
    public function footer()
    {
        return $this->getHeaderOrFooter('footer');
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
            $response = gzencode($this->result());
        }
        else
            $response =& $this->result;

        header(sprintf('Content-Disposition: %s; filename="%s"',
            $asAttachment ? 'attachment' : 'inline',
            $fileName ?: 'Document.pdf')
            );

        die($response);
    }
}

class WKDFHeaderOrFooter
{

    /**
     * @var WKPDF
     */
    private $parent;

    private $headerOrFooter;

    public function __construct(WKPDF $parent, $headerOrFooter)
    {
        $this->parent = $parent;
        $this->headerOrFooter = $headerOrFooter;
    }

    private function setSwitch($switch, $value)
    {
        $this->parent[$this->headerOrFooter . '-' . $switch] = $value;

        return $this;
    }

    /**
     * @return WKPDF
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
}

class WKPDFException extends Exception { }

class WKPDFTOHTMLException extends WKPDFException
{
    public function __construct($message, $code, $error)
    {
        parent::__construct($message, $code);
        $this->error = $error;
    }
    public $error;
}