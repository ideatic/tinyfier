<?php

declare(strict_types=1);

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
    protected bool $_jsCleanComments = true;
    protected ?bool $_isXhtml = null;
    protected string $_replacementHash = '';
    /**
     * @var array<string, string>
     */
    protected array $_placeholders = [];
    /**
     * @var (callable(string): string)|null
     */
    protected $_cssMinifier = null;
    /**
     * @var (callable(string): string)|null
     */
    protected $_jsMinifier = null;

    /**
     * "Minify" an HTML page
     *
     * @param array{cssMinifier?: callable(string): string, jsMinifier?: callable(string): string, jsCleanComments?: bool, xhtml?: bool} $options
     *
     * 'cssMinifier' : (optional) callback function to process content of STYLE
     * elements.
     *
     * 'jsMinifier' : (optional) callback function to process content of SCRIPT
     * elements. Note: the type attribute is ignored.
     *
     * 'xhtml' : (optional boolean) should content be treated as XHTML1.0? If
     * unset, minify will sniff for an XHTML doctype.
     */
    public static function minify(string $html, array $options = []): string
    {
        $min = new self($html, $options);
        return $min->process();
    }


    /**
     * Create a minifier object
     *
     * @param array{cssMinifier?: callable(string): string, jsMinifier?: callable(string): string, jsCleanComments?: bool, xhtml?: bool} $options
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
            // Los ajustes pueden llegar como 0/1 desde fuera
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
     */
    public function process(): string
    {
        $this->_isXhtml ??= str_contains($this->_html, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML');

        $this->_replacementHash = 'MINIFYHTML' . md5((string)($_SERVER['REQUEST_TIME'] ?? time()));
        $this->_placeholders = [];
        $html = $this->_html;

        // replace SCRIPTs (and minify) with placeholders
        $html = self::_check(preg_replace_callback('/(\\s*)<script(\\b[^>]*?>)([\\s\\S]*?)<\\/script>(\\s*)/i', $this->_removeScriptCB(...), $html));

        // replace STYLEs (and minify) with placeholders
        $html = self::_check(preg_replace_callback('/\\s*<style(\\b[^>]*>)([\\s\\S]*?)<\\/style>\\s*/i', $this->_removeStyleCB(...), $html));

        // remove HTML comments (not containing IE conditional comments).
        $html = self::_check(preg_replace_callback('/<!--([\\s\\S]*?)-->/', $this->_commentCB(...), $html));

        // replace PREs with placeholders
        $html = self::_check(preg_replace_callback('/\\s*<pre(\\b[^>]*?>[\\s\\S]*?<\\/pre>)\\s*/i', $this->_removePreCB(...), $html));

        // replace TEXTAREAs with placeholders
        $html = self::_check(preg_replace_callback('/\\s*<textarea(\\b[^>]*?>[\\s\\S]*?<\\/textarea>)\\s*/i', $this->_removeTextareaCB(...), $html));

        // trim each line.
        // @todo take into account attribute values that span multiple lines.
        $html = self::_check(preg_replace('/^\\s+|\\s+$/m', '', $html));

        // remove ws around block/undisplayed elements
        $html = self::_check(
            preg_replace(
                '/\\s+(<\\/?(?:area|base(?:font)?|blockquote|body'
                . '|caption|center|cite|col(?:group)?|dd|dir|div|dl|dt|fieldset|form'
                . '|frame(?:set)?|h[1-6]|head|hr|html|legend|li|link|map|menu|meta'
                . '|ol|opt(?:group|ion)|p|param|t(?:able|body|head|d|h||r|foot|itle)'
                . '|ul)\\b[^>]*>)/i',
                '$1',
                $html
            )
        );

        // remove ws outside of all elements
        $html = self::_check(preg_replace('/>(\\s(?:\\s*))?([^<]+)(\\s(?:\s*))?</', '>$1$2$3<', $html));

        // use newlines before 1st attribute in open tags (to limit line lengths)
        $html = self::_check(preg_replace('/(<[a-z\\-]+)\\s+([^>]+>)/i', "$1\n$2", $html));

        // fill placeholders
        $html = strtr($html, $this->_placeholders);
        // issue 229: multi-pass to catch scripts that didn't get replaced in textareas
        $html = strtr($html, $this->_placeholders);

        return $this->_html = $html;
    }

    /**
     * Las funciones preg_* devuelven null si fallan (por ejemplo, por el límite de backtracking)
     */
    private static function _check(?string $result): string
    {
        if ($result === null) {
            throw new RuntimeException('HTML minification failed: ' . preg_last_error_msg());
        }
        return $result;
    }

    /**
     * @param array<int|string, string> $m
     */
    protected function _commentCB(array $m): string
    {
        return (str_starts_with($m[1], '[') || str_contains($m[1], '<!['))
            ? $m[0]
            : '';
    }

    protected function _reservePlace(string $content): string
    {
        $placeholder = '%' . $this->_replacementHash . count($this->_placeholders) . '%';
        $this->_placeholders[$placeholder] = $content;
        return $placeholder;
    }

    /**
     * @param array<int|string, string> $m
     */
    protected function _removePreCB(array $m): string
    {
        return $this->_reservePlace("<pre{$m[1]}");
    }

    /**
     * @param array<int|string, string> $m
     */
    protected function _removeTextareaCB(array $m): string
    {
        return $this->_reservePlace("<textarea{$m[1]}");
    }

    /**
     * @param array<int|string, string> $m
     */
    protected function _removeStyleCB(array $m): string
    {
        $openStyle = "<style{$m[1]}";
        $css = $m[2];
        // remove HTML comments
        $css = self::_check(preg_replace('/(?:^\\s*<!--|-->\\s*$)/', '', $css));

        // remove CDATA section markers
        $css = $this->_removeCdata($css);

        // minify
        $css = ($this->_cssMinifier ?? trim(...))($css);

        return $this->_reservePlace(
            $this->_needsCdata($css)
                ? "{$openStyle}/*<![CDATA[*/{$css}/*]]>*/</style>"
                : "{$openStyle}{$css}</style>"
        );
    }

    /**
     * @param array<int|string, string> $m
     */
    protected function _removeScriptCB(array $m): string
    {
        $openScript = "<script{$m[2]}";
        $js = $m[3];

        // whitespace surrounding? preserve at least one space
        $ws1 = ($m[1] === '') ? '' : ' ';
        $ws2 = ($m[4] === '') ? '' : ' ';

        // remove HTML comments (and ending "//" if present)
        if ($this->_jsCleanComments) {
            $js = self::_check(preg_replace('/(?:^\\s*<!--\\s*|\\s*(?:\\/\\/)?\\s*-->\\s*$)/', '', $js));
        }

        // remove CDATA section markers
        $js = $this->_removeCdata($js);

        // check type
        $minify = true;
        if (preg_match('/type=[\'"](.*?)[\'"]/i', $m[0], $match)) {
            $minify = strcasecmp($match[1], 'text/javascript') == 0;
        }

        // minify
        $minifier = $minify && $this->_jsMinifier !== null
            ? $this->_jsMinifier
            : trim(...);
        $js = $minifier($js);

        return $this->_reservePlace(
            $this->_needsCdata($js)
                ? "{$ws1}{$openScript}/*<![CDATA[*/{$js}/*]]>*/</script>{$ws2}"
                : "{$ws1}{$openScript}{$js}</script>{$ws2}"
        );
    }

    protected function _removeCdata(string $str): string
    {
        return (str_contains($str, '<![CDATA['))
            ? str_replace(['<![CDATA[', ']]>'], '', $str)
            : $str;
    }

    protected function _needsCdata(string $str): bool
    {
        return $this->_isXhtml && preg_match('/(?:[<&]|\\-\\-|\\]\\]>)/', $str) === 1;
    }
}
