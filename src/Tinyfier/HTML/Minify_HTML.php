<?php
/**
 * Class Minify_HTML
 * @package Minify
 */

/**
 * Compress HTML
 *
 * This is a heavy regex-based removal of whitespace, unnecessary comments and
 * tokens. IE conditional comments are preserved. There are also options to have
 * STYLE and SCRIPT blocks compressed by callback functions.
 *
 * A test suite is available.
 *
 * @package Minify
 * @author  Stephen Clay <steve@mrclay.org>
 */
class Minify_HTML
{
    private string $_html = '';
    /**
     * @var boolean
     */
    protected bool $_jsCleanComments = true;

    /**
     * "Minify" an HTML page
     *
     * @param string $html
     *
     * @param array  $options
     *
     * 'cssMinifier' : (optional) callback function to process content of STYLE
     * elements.
     *
     * 'jsMinifier' : (optional) callback function to process content of SCRIPT
     * elements. Note: the type attribute is ignored.
     *
     * 'xhtml' : (optional boolean) should content be treated as XHTML1.0? If
     * unset, minify will sniff for an XHTML doctype.
     *
     * @return string
     */
    public static function minify(string $html, array $options = []): string
    {
        $min = new self($html, $options);
        return $min->process();
    }


    /**
     * Create a minifier object
     *
     * @param string $html
     *
     * @param array  $options
     *
     * 'cssMinifier' : (optional) callback function to process content of STYLE
     * elements.
     *
     * 'jsMinifier' : (optional) callback function to process content of SCRIPT
     * elements. Note: the type attribute is ignored.
     *
     * 'jsCleanComments' : (optional) whether to remove HTML comments beginning and end of script block
     *
     * 'xhtml' : (optional boolean) should content be treated as XHTML1.0? If
     * unset, minify will sniff for an XHTML doctype.
     */
    public function __construct(string $html, array $options = [])
    {
        $this->_html = str_replace("\r\n", "\n", trim($html));
        if (isset($options['xhtml'])) {
            $this->_isXhtml = (bool)$options['xhtml'];
        }
        if (isset($options['cssMinifier'])) {
            $this->_cssMinifier = $options['cssMinifier'];
        }
        if (isset($options['jsMinifier'])) {
            $this->_jsMinifier = $options['jsMinifier'];
        }
        if (isset($options['jsCleanComments'])) {
            $this->_jsCleanComments = (bool)$options['jsCleanComments'];
        }
    }


    /**
     * Minify the markeup given in the constructor
     *
     * @return string
     */
    public function process(): string
    {
        if ($this->_isXhtml === null) {
            $this->_isXhtml = (str_contains($this->_html, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML'));
        }

        $this->_replacementHash = 'MINIFYHTML' . md5($_SERVER['REQUEST_TIME']);
        $this->_placeholders = [];

        // replace SCRIPTs (and minify) with placeholders
        $this->_html = preg_replace_callback(
            '/(\\s*)<script(\\b[^>]*?>)([\\s\\S]*?)<\\/script>(\\s*)/i'
            ,
            [$this, '_removeScriptCB']
            ,
            $this->_html
        );

        // replace STYLEs (and minify) with placeholders
        $this->_html = preg_replace_callback(
            '/\\s*<style(\\b[^>]*>)([\\s\\S]*?)<\\/style>\\s*/i'
            ,
            [$this, '_removeStyleCB']
            ,
            $this->_html
        );

        // remove HTML comments (not containing IE conditional comments).
        $this->_html = preg_replace_callback(
            '/<!--([\\s\\S]*?)-->/'
            ,
            [$this, '_commentCB']
            ,
            $this->_html
        );

        // replace PREs with placeholders
        $this->_html = preg_replace_callback(
            '/\\s*<pre(\\b[^>]*?>[\\s\\S]*?<\\/pre>)\\s*/i'
            ,
            [$this, '_removePreCB']
            ,
            $this->_html
        );

        // replace TEXTAREAs with placeholders
        $this->_html = preg_replace_callback(
            '/\\s*<textarea(\\b[^>]*?>[\\s\\S]*?<\\/textarea>)\\s*/i'
            ,
            [$this, '_removeTextareaCB']
            ,
            $this->_html
        );

        // trim each line.
        // @todo take into account attribute values that span multiple lines.
        $this->_html = preg_replace('/^\\s+|\\s+$/m', '', $this->_html);

        // remove ws around block/undisplayed elements
        $this->_html = preg_replace(
            '/\\s+(<\\/?(?:area|base(?:font)?|blockquote|body'
            . '|caption|center|cite|col(?:group)?|dd|dir|div|dl|dt|fieldset|form'
            . '|frame(?:set)?|h[1-6]|head|hr|html|legend|li|link|map|menu|meta'
            . '|ol|opt(?:group|ion)|p|param|t(?:able|body|head|d|h||r|foot|itle)'
            . '|ul)\\b[^>]*>)/i',
            '$1',
            $this->_html
        );

        // remove ws outside of all elements
        $this->_html = preg_replace(
            '/>(\\s(?:\\s*))?([^<]+)(\\s(?:\s*))?</'
            ,
            '>$1$2$3<'
            ,
            $this->_html
        );

        // use newlines before 1st attribute in open tags (to limit line lengths)
        $this->_html = preg_replace('/(<[a-z\\-]+)\\s+([^>]+>)/i', "$1\n$2", $this->_html);

        // fill placeholders
        $this->_html = str_replace(
            array_keys($this->_placeholders)
            ,
            array_values($this->_placeholders)
            ,
            $this->_html
        );
        // issue 229: multi-pass to catch scripts that didn't get replaced in textareas
        $this->_html = str_replace(
            array_keys($this->_placeholders)
            ,
            array_values($this->_placeholders)
            ,
            $this->_html
        );
        return $this->_html;
    }

    protected function _commentCB($m)
    {
        return (str_starts_with($m[1], '[') || str_contains($m[1], '<!['))
            ? $m[0]
            : '';
    }

    protected function _reservePlace($content): string
    {
        $placeholder = '%' . $this->_replacementHash . count($this->_placeholders) . '%';
        $this->_placeholders[$placeholder] = $content;
        return $placeholder;
    }

    protected ?bool $_isXhtml = null;
    protected string|null $_replacementHash = null;
    protected array $_placeholders = [];
    protected mixed $_cssMinifier = null;
    protected mixed $_jsMinifier = null;

    protected function _removePreCB($m): string
    {
        return $this->_reservePlace("<pre{$m[1]}");
    }

    protected function _removeTextareaCB($m): string
    {
        return $this->_reservePlace("<textarea{$m[1]}");
    }

    protected function _removeStyleCB($m): string
    {
        $openStyle = "<style{$m[1]}";
        $css = $m[2];
        // remove HTML comments
        $css = preg_replace('/(?:^\\s*<!--|-->\\s*$)/', '', $css);

        // remove CDATA section markers
        $css = $this->_removeCdata($css);

        // minify
        $minifier = $this->_cssMinifier ?: 'trim';
        $css = call_user_func($minifier, $css);

        return $this->_reservePlace(
            $this->_needsCdata($css)
                ? "{$openStyle}/*<![CDATA[*/{$css}/*]]>*/</style>"
                : "{$openStyle}{$css}</style>"
        );
    }

    protected function _removeScriptCB($m): string
    {
        $openScript = "<script{$m[2]}";
        $js = $m[3];

        // whitespace surrounding? preserve at least one space
        $ws1 = ($m[1] === '') ? '' : ' ';
        $ws2 = ($m[4] === '') ? '' : ' ';

        // remove HTML comments (and ending "//" if present)
        if ($this->_jsCleanComments) {
            $js = preg_replace('/(?:^\\s*<!--\\s*|\\s*(?:\\/\\/)?\\s*-->\\s*$)/', '', $js);
        }

        // remove CDATA section markers
        $js = $this->_removeCdata($js);

        // check type
        $minify = true;
        if ($type = preg_match('/type=[\'"](.*?)[\'"]/i', $m[0], $match)) {
            $minify = strcasecmp($match[1], 'text/javascript') == 0;
        }

        // minify
        $minifier = $minify && $this->_jsMinifier
            ? $this->_jsMinifier
            : 'trim';
        $js = call_user_func($minifier, $js);

        return $this->_reservePlace(
            $this->_needsCdata($js)
                ? "{$ws1}{$openScript}/*<![CDATA[*/{$js}/*]]>*/</script>{$ws2}"
                : "{$ws1}{$openScript}{$js}</script>{$ws2}"
        );
    }

    protected function _removeCdata($str)
    {
        return (str_contains($str, '<![CDATA['))
            ? str_replace(['<![CDATA[', ']]>'], '', $str)
            : $str;
    }

    protected function _needsCdata($str): bool
    {
        return ($this->_isXhtml && preg_match('/(?:[<&]|\\-\\-|\\]\\]>)/', $str));
    }
}

