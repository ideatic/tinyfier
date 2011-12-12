<?php

/**
 * Compression and processing routines for Javascript code
 *
 * @package Tinyfier
 */
class JS {

    /**
     * Compress javascript code
     *
     * Available settings:
     *   'pretty': if true, adds line breaks and indentation to its output code to make the code easier for humans to read
     *   'gclosure': allow to use the external google closure compiler
     *
     * @param string $source
     * @param array $settings
     * @return string
     */
    public static function compress($source, $settings = array()) {
        //Default settings
        $settings = $settings + array(
            'gclosure' => true,
            'pretty' => false
        );

        //Compress using Google Closure compiler
        if ($settings['gclosure'] && strlen($source) > 750) {
            $compiled = self::_compress_google_closure($source, 1, $settings['pretty']);
            if ($compiled !== false)
                return $compiled;
        }

        //Compile using JSMinPlus
        if ($settings['pretty']) {
            return $source;
        } else {
            require 'jsminplus.php';
            return JSMinPlus::minify($source);
        }
    }

    /**
     * Compiles javascript code using the Google Closure Compiler API
     * @see http://code.google.com/intl/es/closure/compiler/docs/api-ref.html
     * @param string $source
     * @param int $level (0: WHITESPACE_ONLY, 1: SIMPLE_OPTIMIZATIONS, 2: ADVANCED_OPTIMIZATIONS)
     * @param bool $pretty
     * @return mixed Code compressed, false if error
     */
    private static function _compress_google_closure($source, $level=1, $pretty=false) {
        if (!function_exists('curl_exec'))
            return false;

        //Generate POST data
        $postData = array(
            'output_info' => 'compiled_code',
            'output_format' => 'text',
            'js_code' => $source,
            'compilation_level' => $level == 0 ? 'WHITESPACE_ONLY' : ($level == 2 ? 'ADVANCED_OPTIMIZATIONS' : 'SIMPLE_OPTIMIZATIONS')
        );
        if ($pretty) {
            $postData['formatting'] = 'pretty_print';
        }

        //Remote compile
        $ch = curl_init('http://closure-compiler.appspot.com/compile');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $output = curl_exec($ch);
        curl_close($ch);

        if ($output !== false && substr($output, 0, 5) != 'Error') {
            return $output;
        }
        return false;
    }

}