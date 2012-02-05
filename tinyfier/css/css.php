<?php

/**
 * Rutinas de compresión y procesado de código CSS
 *
 * @package Tinyfier
 */
abstract class CSS {

    /**
     * Settings
     * @var array
     */
    private $_settings;

    /**
     * Process and compress CSS code
     * 
     * Available settings:
     *   'pretty': if true, adds line breaks and indentation to its output code to make the code easier for humans to read
     *   'absolute_path': absolute path to the file
     *   'relative_path': relative path from the document root
     *   'cache_path': cache folder
     *   'ie_compatible': boolean value that indicates if the generated css will be compatible with old IE versions
     *   'data': array with the vars passed to the css parser for use in the code
     * 
     * @param string $css
     * @param array $settings
     * @return string 
     */
    public static function process($settings = array()) {
        //Load settings
        $settings = $settings + array(
            'absolute_path' => '',
            'relative_path' => '',
            'cache_path' => '',
            'pretty' => false,
            'ie_compatible' => false,
            'data' => null
        );

        // 1. Load file and helpers
        $css = file_get_contents($settings['absolute_path']);
        $helpers = file_get_contents(dirname(__FILE__) . '/helpers.less');

        // 2. Process the file with LESS
        require_once 'tinyfier_less.php';
        $less = new tinyfier_less($settings);
        $less->importDir = dirname($settings['absolute_path']);
        $less->disable_inline = $settings['ie_compatible'];
        $css = $less->parse("$helpers\n$css", $settings['data']);

        // 3. Parse and remove IE hacks
        require_once 'css_document.php';
        $css_document = new css_document();
        $css_document->parse($css);
        if ($settings['ie_compatible'] == false) {
            self::_remove_hacks($css_document);
        }

        // 4 . Compress
        $css = $css_document->save($settings['pretty']);

        return $css;
    }

    /**
     * Remove IE hacks from the document
     * @param css_document $doc
     */
    private static function _remove_hacks(css_document $doc) {
        foreach ($doc->selectors() as $selector) {
            foreach ($selector->properties() as $property) {
                if (preg_match('/^\s*(filter|_|\*|-(?!moz|webkit))/', $property->Name) || stripos($property->Value, 'expression') === 0) {
                    $property->delete();
                }
            }
        }
    }

}
