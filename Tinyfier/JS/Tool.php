<?php

/**
 * Compression and processing routines for Javascript code
 */
abstract class Tinyfier_JS_Tool {

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
    public static function process($source, array $settings = array(), &$errors = array(), &$warnings = NULL) {
        //Default settings
        $settings = $settings + self::default_settings();

        //Compress using Google Closure compiler
        if ($settings['external_services'] && $settings['gclosure']) {
            $compiled = self::_compress_google_closure($source, $settings['level'], $settings['pretty'], $errors, $warnings);
            if ($compiled !== FALSE) {
                return $compiled;
            }
        }

        //Compile using JSMinPlus
        if ($settings['pretty']) {
            return $source;
        } else {
            require_once 'jsminplus.php';

            //Remember: modify JSMinPlus::minify to not catch errors
            try {
                $result = JSMinPlus::minify($source);
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                return $source;
            }

            return $result;
        }
    }

    const LEVEL_WHITESPACE_ONLY = 'WHITESPACE_ONLY';
    const LEVEL_SIMPLE_OPTIMIZATIONS = 'SIMPLE_OPTIMIZATIONS';
    const LEVEL_ADVANCED_OPTIMIZATIONS = 'ADVANCED_OPTIMIZATIONS';

    public static function default_settings() {
        return array(
            'external_services' => TRUE, //Use external compressors (like gclosure)
            'gclosure' => TRUE,
            'level' => self::LEVEL_SIMPLE_OPTIMIZATIONS,
            'pretty' => FALSE
        );
    }

    /**
     * Compiles javascript code using the Google Closure Compiler API
     * @see http://code.google.com/intl/es/closure/compiler/docs/api-ref.html
     * @param string $source
     * @param int $level One of LEVEL_* constants
     * @param bool $pretty
     * @return mixed Code compressed, FALSE if error
     */
    private static function _compress_google_closure($source, $level = self::LEVEL_SIMPLE_OPTIMIZATIONS, $pretty = FALSE, &$errors = array(), &$warnings = NULL) {
        if (!function_exists('curl_exec')) {
            return FALSE;
        }

        //Generate POST data
        $post = array(
            'output_info' => 'compiled_code',
            'output_format' => 'json',
            'warning_level' => isset($warnings) ? 'VERBOSE' : 'QUIET',
            'compilation_level' => $level,
            'js_code' => $source,
        );
        if ($pretty) {
            $post['formatting'] = 'pretty_print';
        }

        //Remote compile
        $ch = curl_init('http://closure-compiler.appspot.com/compile');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post) . '&output_info=warnings&output_info=errors');
        $output = curl_exec($ch);
        curl_close($ch);
        if ($output === FALSE) {
            return FALSE;
        }

        $compilation_result = json_decode($output, TRUE);

        if (!$compilation_result) {
            return FALSE;
        }

        if (!empty($compilation_result['errors'])) {
            foreach ($compilation_result['errors'] as $error) {
                $errors[] = "{$error['type']}: {$error['error']} at line {$error['lineno']}, character {$error['charno']}";
            }
        }
        if (!empty($compilation_result['serverErrors'])) {
            foreach ($compilation_result['serverErrors'] as $error) {
                $errors[] = "{$error['code']}: {$error['error']}";
            }
        }
        if (isset($warnings) && !empty($compilation_result['warnings'])) {
            foreach ($compilation_result['warnings'] as $warning) {
                $warnings[] = "{$warning['type']}: {$warning['warning']} at line {$warning['lineno']}, character {$warning['charno']}";
            }
        }

        return empty($errors) ? $compilation_result['compiledCode'] : FALSE;
    }

}
