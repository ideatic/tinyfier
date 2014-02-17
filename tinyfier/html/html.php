<?php

/**
 * Rutinas de compresión y procesado de código HTML
 *
 * @package Tinyfier
 */
abstract class TinyfierHTML {

    private static $_settings;

    /**
     * Remove whitespaces from HTML code
     * @param string $html
     * @param boolean $compress_all Compress embedded css and js code
     * @return string
     */
    public static function process($html, array $settings = array()) {
        require_once dirname(__FILE__) . '/Minify_HTML.php';

        $settings = self::$_settings = $settings + array(
            'compress_all' => TRUE,
            'markers' => array(
                '<?'
            ),
            'external_services' => TRUE
        );

        if ($settings['compress_all']) {
            require_once dirname(dirname(__FILE__)) . '/css/css.php';
            require_once dirname(dirname(__FILE__)) . '/js/js.php';

            return Minify_HTML::minify($html, array(
                        'cssMinifier' => 'TinyfierHTML::_compress_inline_css',
                        'jsMinifier' => 'TinyfierHTML::_compress_inline_js'
            ));
        } else {
            return Minify_HTML::minify($html);
        }
    }

    /**
     * Compress inline CSS code found in a HTML file.
     * Only por internal usage.
     * @access private
     */
    public static function _compress_inline_css($css) {
        if (self::_has_mark($css)) {
            return $css;
        } else {
            return TinyfierCSS::process($css, array(
                        'use_less' => FALSE,
                        'ie_compatible' => TRUE,
                        'external_services' => self::$_settings['external_services']
            ));
        }
    }

    /**
     * Compress inline JS code found in a HTML file.
     * Only por internal usage.
     * @access private
     */
    public static function _compress_inline_js($js) {
        if (self::_has_mark($js)) {
            return $js;
        } else {
            return TinyfierJS::process($js, array(
                        'external_services' => self::$_settings['external_services']
            ));
        }
    }

    /**
     * Comprobar si el código tiene alguna de las marcas establecidas que evitan su compresión.
     * Se utiliza para evitar que fragmentos de código que lleven incustrado código PHP
     * se compriman y den lugar a pérdida de datos
     */
    private static function _has_mark($code) {
        foreach (self::$_settings['markers'] as $mark) {
            if (strpos($code, $mark) !== FALSE) {
                return TRUE;
            }
        }
        return FALSE;
    }

}
