<?php

declare(strict_types=1);

/**
 * Tools for HTML optimization and compression
 *
 * @phpstan-import-type CssSettings from Tinyfier_CSS_Tool
 * @phpstan-import-type JsSettings from Tinyfier_JS_Tool
 * @phpstan-type HtmlSettings array{
 *     // Comprimir también el CSS y el JS en línea
 *     compress_all?: bool,
 *     // Ajustes para Tinyfier_CSS_Tool::process (el CSS en línea nunca usa LESS)
 *     css?: CssSettings,
 *     // Ajustes para Tinyfier_JS_Tool::process
 *     js?: JsSettings,
 *     // Si el CSS o JS en línea contiene una de estas cadenas, no se comprime (por ejemplo, código PHP)
 *     markers?: list<string>,
 * }
 */
abstract class Tinyfier_HTML_Tool
{
    /**
     * @var array{compress_all: bool, css: CssSettings, js: JsSettings, markers: list<string>}
     */
    private static array $_settings;

    /**
     * Remove whitespaces from HTML code
     *
     * @param HtmlSettings $settings
     */
    public static function process(string $html, array $settings = []): string
    {
        require_once dirname(__FILE__) . '/Minify_HTML.php';

        $settings += [
            'compress_all'      => true,
            'css'               => [],
            'js'                => [],
            'markers'           => [
                '<?'
            ],
        ];

        // Normalizar los tipos, porque los ajustes pueden venir de configuración
        self::$_settings = [
            'compress_all' => (bool)$settings['compress_all'],
            'css'          => is_array($settings['css']) ? $settings['css'] : [],
            'js'           => is_array($settings['js']) ? $settings['js'] : [],
            'markers'      => array_values(array_map(strval(...), is_array($settings['markers']) ? $settings['markers'] : [])),
        ];

        if ((bool)$settings['compress_all']) {
            return Minify_HTML::minify(
                $html,
                [
                    'cssMinifier' => self::_compress_inline_css(...),
                    'jsMinifier'  => self::_compress_inline_js(...)
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
                    'less' => false,
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
            return Tinyfier_JS_Tool::process($js, self::$_settings['js']);
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
