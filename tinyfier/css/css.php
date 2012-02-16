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

        // 1. Process the file with LESS
        require_once 'less/tinyfier_less.php';
        $less = new tinyfier_less($settings['absolute_path'], $settings);
        $css = $less->parse(null, $settings['data']);
        
        // 2. Optimize, add vendor prefix and remove hacks
        require_once 'css_optimizer.php';
        $optimizer = new css_optimizer(array(
                    'compress' => true,
                    'optimize' => true,
                    'extra_optimize' => false,
                    'remove_ie_hacks' => $settings['ie_compatible']==false,
                    'prefix' => 'all',
                ));
        $css = $optimizer->process($css);

        return $css;
    }
}
