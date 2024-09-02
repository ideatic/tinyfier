<?php

/**
 * Tools for HTML optimization and compression
 */
abstract class Tinyfier_HTML_Tool
{
    private static array $_settings;

    /**
     * Remove whitespaces from HTML code
     */
    public static function process(string $html, array $settings = []): string
    {
        require_once dirname(__FILE__) . '/Minify_HTML.php';

        $settings = self::$_settings = $settings + [
                'compress_all'      => true,
                'css'               => [],
                'js'                => [],
                'markers'           => [
                    '<?'
                ],
                'external_services' => true,
            ];

        if ($settings['compress_all']) {
            return Minify_HTML::minify(
                $html,
                [
                    'cssMinifier' => [__CLASS__, '_compress_inline_css'],
                    'jsMinifier'  => [__CLASS__, '_compress_inline_js']
                ]
            );
        } else {
            return Minify_HTML::minify($html);
        }
    }

    /**
     * Compress inline CSS code found in a HTML file.
     * Only por internal usage.
     * @access private
     */
    public static function _compress_inline_css(string $css): string
    {
        if (self::_has_mark($css)) {
            return $css;
        } else {
            return Tinyfier_CSS_Tool::process(
                $css,
                self::$_settings['css'] + [
                    'less'              => false,
                    'external_services' => self::$_settings['external_services']
                ]
            );
        }
    }

    /**
     * Compress inline JS code found in a HTML file.
     * Only por internal usage.
     * @access private
     */
    public static function _compress_inline_js(string $js): string
    {
        if (self::_has_mark($js)) {
            return $js;
        } else {
            return Tinyfier_JS_Tool::process(
                $js,
                self::$_settings['js'] + [
                    'external_services' => self::$_settings['external_services']
                ]
            );
        }
    }

    /**
     * Comprobar si el código tiene alguna de las marcas establecidas que evitan su compresión.
     * Se utiliza para evitar que fragmentos de código que lleven incustrado código PHP
     * se compriman y den lugar a pérdida de datos
     */
    private static function _has_mark(string $code): bool
    {
        foreach (self::$_settings['markers'] as $mark) {
            if (str_contains($code, $mark)) {
                return true;
            }
        }
        return false;
    }

}
