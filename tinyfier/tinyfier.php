<?php

/**
 * Tinyfier 0.3 (https://github.com/javiermarinros/Tinyfier)
 *
 * @author Javier Marín <www.digitalestudio.es>
 * @license http://opensource.org/licenses/bsd-license.php  New BSD License
 * @see http://www.digitalestudio.es/proyectos/tinyfier/
 * @package Tinyfier
 */
/*
 * Configuration
 */
if (!isset($cache_dir))
    $cache_dir = dirname(__FILE__) . '/cache'; //Path to cache folder
if (!isset($auto_compatibility_mode))
    $auto_compatibility_mode = TRUE; // Detect automatically IE7-
if (!isset($max_age))
    $max_age = isset($_GET['max-age']) ? $_GET['max-age'] : 604800; //Max time for user cache (default: 1 week)
if (!isset($separator))
    $separator = ','; //Source files separator
if (!isset($optimize_images))
    $optimize_images = TRUE; //Optimize images using external APIs like Yahoo Smush.it and TinyPNG.org
if (!isset($tinypng_api_key))
    $tinypng_api_key = '';

$debug = isset($_GET['debug']); //Debug mode, useful during development
$recache = isset($_GET['recache']); //Disable cache

/*
 * Main code
 */
//Set error reporting
error_reporting(E_ALL & ~E_STRICT);
ini_set('display_errors', $debug);
ini_set('log_errors', 'On');
ini_set('error_log', isset($error_log) ? $error_log : dirname(__FILE__) . '/error_log.txt');

//Get input string
if (isset($_GET['f'])) {
    $input_string = $_GET['f'];
} else if (isset($_SERVER['PATH_INFO'])) {
    $input_string = $_SERVER['PATH_INFO'][0] == '/' ? substr($_SERVER['PATH_INFO'], 1) : $_SERVER['PATH_INFO'];
} else if (isset($_SERVER['ORIG_PATH_INFO'])) {
    $input_string = $_SERVER['ORIG_PATH_INFO'][0] == '/' ? substr($_SERVER['ORIG_PATH_INFO'], 1) : $_SERVER['ORIG_PATH_INFO'];
} else {
    die('Input not found');
}

//Check that source files are safe and well-formed
$input_string = str_replace("\x00", '', (string) $input_string);//Protect null bytes (http://www.php.net/manual/en/security.filesystem.nullbytes.php)
if (empty($input_string) || strpos($input_string, '//') !== FALSE || strpos($input_string, '\\') !== FALSE || strpos($input_string, './') !== FALSE) {
    header('HTTP/1.0 400 Bad Request');
    die('Invalid source files');
}
$input_data = explode($separator, $input_string);

//Get source files path, type, last mod time, and input vars
$last_modified = 0;
$files = array();
$vars = array();
$type = NULL;
$valid_extensions = array('js', 'css', 'less');
if (!isset($src_folder))
    $src_folder = dirname(dirname($_SERVER['SCRIPT_FILENAME']));
foreach ($input_data as $input) {
    if (strpos($input, '=') !== FALSE) { //Input data
        $parts = explode('=', urldecode($input));
        $vars[$parts[0]] = $parts[1];
    } else { //Input file
        $absolute_path = $input[0] == '/' ? "{$_SERVER['DOCUMENT_ROOT']}/$input" : "$src_folder/$input";

        if (!is_readable($absolute_path)) { //Check if file exits in the last file folder
            $absolute_path = isset($last_path) ? dirname($last_path) . '/' . $input : FALSE;
            if ($absolute_path === FALSE || !is_readable($absolute_path)) { //File not found
                header('HTTP/1.0 400 Bad Request');
                die("File '$input' not found");
            }
        }

        $extension = $pos = substr($input, strrpos($input, '.') + 1);
        if (empty($extension) || !in_array($extension, $valid_extensions)) {
            header('HTTP/1.0 400 Bad Request');
            die('Invalid filetype');
        } else if (!isset($type)) {
            $type = $extension;
        }

        $last_modified = max($last_modified, filemtime($absolute_path));
        $last_path = $absolute_path;
        $files[$input] = $absolute_path;
    }
}

//Etag header
if (!$debug) {
    $etag = '"' . crc32($input_string . $last_modified) . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && strcmp($_SERVER['HTTP_IF_NONE_MATCH'], $etag) == 0 && !$recache) {
        header('HTTP/1.1 304 Not Modified');
        header('Content-Length: 0');
        exit;
    } else {
        header("Etag: $etag");
    }
}

//IF-MODIFIED-SINCE header
if (!$debug) {
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && date_default_timezone_set('GMT') && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $last_modified && !$recache) {
        header('HTTP/1.1 304 Not Modified');
        header('Content-Length: 0');
        exit;
    } else {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $last_modified));
    }
}

//Check if browser supports GZIP compression
$accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? strtolower($_SERVER['HTTP_ACCEPT_ENCODING']) : '';
if (strpos($accept_encoding, 'gzip') !== FALSE) {
    $encoding = 'gzip';
} else if (strpos($accept_encoding, 'deflate') !== FALSE) {
    $encoding = 'deflate';
} else {
    $encoding = 'none';
}

//Extra check for GZIP support (from Minify project, http://code.google.com/p/minify/)
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
if (strpos($ua, 'Mozilla/4.0 (compatible; MSIE ') === 0 && strpos($ua, 'Opera') === FALSE) { // quick escape for non-IEs
    $IE_version = (float) substr($ua, 30);
    if ($IE_version < 6 || ($IE_version == 6 && FALSE === strpos($ua, 'SV1'))) { // IE < 6 SP1 don't support GZIp compression
        $encoding = 'none';
    }
}

//Send HTTP headers
header('Vary: Accept-Encoding');
header('Content-Type: ' . ($type == 'js' ? 'text/javascript' : 'text/css'));
if ($debug || $recache) {
    header("Expires: on, 01 Jan 1970 00:00:00 GMT");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
} else {
    header("Cache-Control: max-age={$max_age}, public");
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', $_SERVER['REQUEST_TIME'] + $max_age));
}

//Compatible mode for IE7-?
$compatible_mode = $type == 'js' ? FALSE : ($auto_compatibility_mode && !isset($_GET['compatible']) ? isset($IE_version) && $IE_version <= 7 : isset($_GET['compatible']));

//Check cache
$cache_prefix = 'tynifier_' . md5($input_string);
$cache_id = $cache_prefix . '_' . $last_modified . ($compatible_mode ? '_compatible' : '') . '.' . $type;
$cache_file = $cache_dir . '/' . $cache_id . ($encoding != 'none' ? ".$encoding" : '');

if (!file_exists($cache_file) || $debug || $recache) :

    //Process source code
    try {
        if ($type == 'js') { //Combine, then compress
            require 'js/js.php';

            //Combine
            $source = array();
            foreach ($files as $relative_path => $absolute_path) {
                if ($debug) {
                    $source [] = "\n\n\n/* $relative_path */\n\n\n";
                }
                $source [] = file_get_contents($absolute_path) . "\n";
            }

            //Compress
            $source = implode(';', $source);
            $source = TinyfierJS::process($source, array(
                        'pretty' => $debug,
                        'gclosure' => strlen($source) > 750 && !$debug //No usar Google Closure en modo debug o para javascript pequeños
            ));
        } elseif ($type == 'css' || $type == 'less') { //Process and compress, then combine
            require 'css/css.php';

            //Process and compress
            $source = array();
            foreach ($files as $relative_path => $absolute_path) {
                if ($debug) {
                    $source[] = "\n\n\n/* $relative_path */\n\n\n";
                }

                $source[] = TinyfierCSS::process(NULL, array(
                            'absolute_path' => $absolute_path,
                            'relative_path' => $relative_path,
                            'cache_path' => $cache_dir,
                            'compress' => !$debug,
                            'data' => $vars,
                            'ie_compatible' => $compatible_mode,
                            'optimize_images' => !$debug && $optimize_images,
                ));
            }
            //Combine
            $source = implode("\n", $source);
        } else {
            header('HTTP/1.0 400 Bad Request');
            die('Invalid source type');
        }
    } catch (Exception $err) {
        header('HTTP/1.1 500 Internal Server Error');
        die($err->getMessage());
    }

    if ($debug) {
        echo $source;
        return;
    }

    //Save cache
    if (file_put_contents("$cache_dir/$cache_id", $source) === FALSE ||
            file_put_contents("$cache_dir/$cache_id.gzip", gzencode($source, 9, FORCE_GZIP)) === FALSE ||
            file_put_contents("$cache_dir/$cache_id.deflate", gzencode($source, 9, FORCE_DEFLATE)) === FALSE
    ) {
        //Check if cache folder exists, and if not, create it
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755);
        }
        header('HTTP/1.1 500 Internal Server Error');
        die('Error writing cache');
    }

    //Delete old cache copies
    $dirh = opendir($cache_dir);
    while (($file = readdir($dirh)) !== FALSE) {
        if (strpos($file, $cache_prefix) === 0 && filemtime("$cache_dir/$file") < $last_modified) {
            unlink("$cache_dir/$file");
        }
    }
    closedir($dirh);

    clearstatcache();

endif;

//Send file from cache
if ($encoding != 'none') {
    header("Content-Encoding: $encoding");
}
if (($fs = filesize($cache_file)) !== FALSE) {
    header('Content-Length: ' . $fs);
}
if (readfile($cache_file) === FALSE) {
    //Error
    header('HTTP/1.1 500 Internal Server Error');
    die('Error reading cache');
}