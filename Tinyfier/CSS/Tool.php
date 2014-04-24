<?php

/**
 * Compression and processing routines for CSS code
 */
abstract class Tinyfier_CSS_Tool {

    /**
     * Process a CSS file
     * @param string $file
     * @param array $settings
     * @return string
     */
    public static function process_file($file, array $settings = array()) {
        $settings['absolute_path'] = $file;
        return self::process(file_get_contents($file), $settings);
    }

    /**
     * Process CSS code
     *
     * Available settings:
     *   'less': enable/disable LESS parser
     *   'compress': TRUE to remove whispaces and unused chars from output, FALSE to make it more human readable
     *   'absolute_path': absolute path to the file
     *   'url_path': url path to the file
     *   'cache_path': cache folder
     *   'data': variables passed to the css parser for use in the LESS code
     *
     * @param string $css
     * @param array $settings
     * @return string
     */
    public static function process($css, array $settings = array()) {
        //Load settings
        $settings = $settings + self::default_settings();

        // 1. Process the file with LESS    
        if ($settings['less']) {
            require_once 'less/tinyfier_less.php';
            $less = new tinyfier_less();
            $css = $less->process($css, $settings);
        }

        // 2. Optimize, compress and add vendor prefixes
        require_once 'css_optimizer/css_optimizer.php';
        $optimizer = new css_optimizer(array(
            'compress' => $settings['compress'],
            'optimize' => $settings['optimize'],
            'extra_optimize' => $settings['extra_optimize'],
            'remove_ie_hacks' => FALSE,
            'prefix' => $settings['prefix'],
        ));
        $css = $optimizer->process($css);

        return $css;
    }

    public static function default_settings() {
        return array(
            'less' => TRUE,
            'absolute_path' => '',
            'url_path' => '',
            'cache_path' => '',
            'compress' => TRUE,
            'optimize' => TRUE,
            'extra_optimize' => FALSE,
            'optimize_images' => TRUE,
            'lossy_quality' => 75,
            'data' => NULL,
            'prefix' => 'all'
        );
    }

}
