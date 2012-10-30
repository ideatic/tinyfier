<?php

/**
 * Compression and processing routines for Javascript code
 *
 * @package Tinyfier
 */
abstract class TinyfierJS {

    /**
     * Compress javascript code
     *
     * Available settings:
     *   'pretty': if TRUE, adds line breaks and indentation to its output code to make the code easier for humans to read
     *   'gclosure': allow to use the external google closure compiler
     *
     * @param string $source
     * @param array $settings
     * @return string
     */
    public static function compress($source, array $settings = array()) {
        //Default settings
        $settings = $settings + array(
            'gclosure' => TRUE,
            'pretty' => FALSE
        );

        //Compress using Google Closure compiler
        if ($settings['gclosure'] && strlen($source) > 750) {
            $compiled = self::_compress_google_closure($source, 1, $settings['pretty']);
            if ($compiled !== FALSE)
                return $compiled;
        }

        //Compile using JSMinPlus
        if ($settings['pretty']) {
            return $source;
        } else {
            require_once 'jsminplus.php';
            ob_start(); //Capture output, JSMinPlus echo errors by default
            $result = JSMinPlus::minify($source);
            $errors = ob_get_clean();
            if (empty($errors)) { //Success
                return $result;
            } else { //Return original source
                return $source;
            }
        }
    }

    /**
     * Compiles javascript code using the Google Closure Compiler API
     * @see http://code.google.com/intl/es/closure/compiler/docs/api-ref.html
     * @param string $source
     * @param int $level (0: WHITESPACE_ONLY, 1: SIMPLE_OPTIMIZATIONS, 2: ADVANCED_OPTIMIZATIONS)
     * @param bool $pretty
     * @return mixed Code compressed, FALSE if error
     */
    private static function _compress_google_closure($source, $level = 1, $pretty = FALSE) {
        if (!function_exists('curl_exec'))
            return FALSE;

        //Generate POST data
        $post = array(
            'output_info' => 'compiled_code',
            'output_format' => 'text',
            'compilation_level' => $level == 0 ? 'WHITESPACE_ONLY' : ($level == 2 ? 'ADVANCED_OPTIMIZATIONS' : 'SIMPLE_OPTIMIZATIONS'),
            'js_code' => $source,
        );
        if ($pretty) {
            $post['formatting'] = 'pretty_print';
        }

        //Remote compile
        $ch = curl_init('http://closure-compiler.appspot.com/compile');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $output = curl_exec($ch);
        curl_close($ch);

        if ($output === FALSE || stripos($output, 'error') === 0) {
            return FALSE;
        }
        return $output;
    }

}