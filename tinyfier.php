<?php

/**
 * Tinyfier 0.1 (http://www.digitalestudio.es/proyectos/tinyfier/)
 *
 * Mucha parte del código se ha obtenido de otros paquetes y librerías como:
 *
 *  -CSScaffold (http://github.com/anthonyshort/csscaffold)
 *  -Minify (http://code.google.com/p/minify/)
 *
 * @author Javier Marín <www.digitalestudio.es>
 * @license http://opensource.org/licenses/bsd-license.php  New BSD License
 * @copyright 2009 Javier Marín. All rights reserved.
 * @see http://www.digitalestudio.es/proyectos/tinyfier/
 * @package Tinyfier
 */
/*
 * Configuración
 */
if (!isset($cache_dir))
    $cache_dir = dirname(__FILE__) . '/cache'; //Ruta al directorio donde se guardará la caché
$auto_compatibility_mode = true; //Establecer a true para detectar automáticamente IE7- a la hora de generar CSS compatible (emplear parametro &compatible cuando este valor el falso para activar el modo compatible)
$max_age = isset($_GET['max-age']) ? $_GET['max-age'] : 1800; //El cliente comprobará nuevas versiones del archivo cada 30 Minutos
$file_separator = ','; //Carácter usado para separar los ficheros en la url de la petición

$debug = isset($_GET['debug']); //Modo debug, útil durante el diseño de una página
$recache = isset($_GET['recache']); //Desactivar la caché

/*
 * Código principal
 */
//Establecer registro de errores
error_reporting(E_ALL & ~E_STRICT);
ini_set('display_errors', false);
ini_set('log_errors', 'On');
ini_set('error_log', dirname(__FILE__) . '/error_log.txt');

//Obtener archivos fuente
if (isset($_SERVER['PATH_INFO'])) {
    $sources = $_SERVER['PATH_INFO'][0] == '/' ? substr($_SERVER['PATH_INFO'], 1) : $_SERVER['PATH_INFO'];
} else if (isset($_GET['f'])) {
    $sources = $_GET['f'];
} else {
    $script_path = dirname($_SERVER['PHP_SELF']) . '/';
    $sources = strpos($_SERVER['REQUEST_URI'], $script_path) === 0 ? substr($_SERVER['REQUEST_URI'], strlen($script_path)) : $_SERVER['REQUEST_URI'];
    if (strpos($sources, '?') !== false)
        $sources = substr($sources, 0, strpos($sources, '?'));
}

//Comprobar que la lista de archivos fuente no contiene caracteres inseguros (\\, //, ./, ../), está bien formada y todas las extensiones son iguales
if (empty($sources) || !preg_match('/^[^,]+\\.(css|js)(?:,[^,]+\\.\\1)*$/', $sources, $type) || strpos($sources, '//') !== false
        || strpos($sources, '\\') !== false || strpos($sources, './') !== false) {
    header('HTTP/1.0 400 Bad Request');
    die('Invalid source files or filetype');
}
$type = $type[1];
$source_files = explode($file_separator, $sources);

//Obtener lista de ficheros a combinar y comprobar que son correctos
$last_modified = 0;
$files = array();
$base_path = dirname(dirname($_SERVER['SCRIPT_FILENAME']));
foreach ($source_files as $relative_path) {
    $absolute_path = $relative_path[0] == '/' ? "{$_SERVER['DOCUMENT_ROOT']}/$relative_path" : "$base_path/$relative_path";

    if (!is_readable($absolute_path)) {//Ruta normal no válida, probar buscando el archivo en la última carpeta accedida
        $absolute_path = isset($last_path) ? dirname($last_path) . '/' . $relative_path : false;
        if ($absolute_path === false || !is_readable($absolute_path)) { //No se encuentra el fichero
            header('HTTP/1.0 400 Bad Request');
            die("File '$relative_path' not valid (Path '$absolute_path' not found)");
        }
    }

    $last_modified = max($last_modified, filemtime($absolute_path));
    $last_path = $absolute_path;
    $files[$relative_path] = $absolute_path;
}

//Comprobar fecha de última modificación
if (!$debug) {
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && date_default_timezone_set('GMT') && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $last_modified && !$recache) {
        header('HTTP/1.1 304 Not Modified');
        header('Content-Length: 0');
        exit;
    } else {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $last_modified));
    }
}

//Determinar si el navegador soporta compresión
$accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? strtolower($_SERVER['HTTP_ACCEPT_ENCODING']) : '';
if (strpos($accept_encoding, 'gzip') !== false) {
    $encoding = 'gzip';
} else if (strpos($accept_encoding, 'deflate') !== false) {
    $encoding = 'deflate';
} else {
    $encoding = 'none';
}

//Comprobar versiones del navegador (Obtenido del paquete Minify)
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
if (strpos($ua, 'Mozilla/4.0 (compatible; MSIE ') === 0 && strpos($ua, 'Opera') === false) {// quick escape for non-IEs
    $IE_version = (float) substr($ua, 30);
    if ($IE_version < 6 || ($IE_version == 6 && false === strpos($ua, 'SV1'))) {// Versiones de IE < 6 SP1 dan error con la compresión gzip
        $encoding = 'none';
    }
}

//Enviar cabeceras
header('Vary: Accept-Encoding');
header('Content-Type: ' . ($type == 'js' ? 'application/x-javascript' : 'text/css'));
if (!$debug) {
    header("Cache-Control: max-age={$max_age}, public");
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', $_SERVER['REQUEST_TIME'] + $max_age));
}

//Decidir si se carga el CSS en modo compatible
$compatible_mode = $type == 'css' ? ($auto_compatibility_mode && !isset($_GET['compatible']) ? isset($IE_version) && $IE_version <= 7 : isset($_GET['compatible'])) : false;

//Comprobar si existe versión en caché para la petición
//$cache_prefix = 'tynifier_' . md5(serialize($_GET));
$cache_prefix = 'tynifier_' . md5($sources);
$cache_id = $cache_prefix . '_' . $last_modified . ($compatible_mode ? '_compatible' : '') . '.' . $type;
$cache_file = $cache_dir . '/' . $cache_id . ($encoding != 'none' ? ".$encoding" : '');

if (!file_exists($cache_file) || $debug || $recache) :

    //Procesar código fuente
    try {
        if ($type == 'js') { //Combinar, luego procesar
            require 'js.php';

            //Combinar ficheros fuente Javascript
            $source = '';
            foreach ($files as $relative_path => $absolute_path) {
                if ($debug)
                    $source .= "\n\n\n/* $relative_path */\n\n\n";
                $source .= file_get_contents($absolute_path) . "\n";
            }

            //Procesar resultado
            $source = JS::Process($source, array(
                        'pretty' => $debug,
                        'gclosure' => !$debug //No usar Google Closure en modo debug
                    ));
        } elseif ($type == 'css') { //Procesar, luego combinar (ya que cada CSS depende de su ruta relativa)
            require 'css.php';

            //Procesar ficheros fuente
            $css_sources = array();
            foreach ($files as $relative_path => $absolute_path) {
                if ($debug)
                    $css_sources[] = "\n\n\n/* $relative_path */\n\n\n";

                $css_sources[] = CSS::Process(file_get_contents($absolute_path), array(
                            'path' => $absolute_path,
                            'absolute_path' => $absolute_path,
                            'relative_path' => $relative_path,
                            'cache_path' => $cache_dir,
                            'pretty' => $debug,
                            'ie_compatible' => $compatible_mode
                        ));
            }

            //Combinar resultado
            $source = trim(implode('', $css_sources));
        }
    } catch (Exception $err) {
        header('HTTP/1.1 500 Internal Server Error');
        die($err->getMessage());
    }

    if ($debug) {
        echo $source;
        return;
    }

    //Guardar en caché
    if (file_put_contents("$cache_dir/$cache_id", $source) === false ||
            file_put_contents("$cache_dir/$cache_id.gzip", gzencode($source, 9, FORCE_GZIP)) === false ||
            file_put_contents("$cache_dir/$cache_id.deflate", gzencode($source, 9, FORCE_DEFLATE)) === false) {
        header('HTTP/1.1 500 Internal Server Error');
        die('Error writing cache');
    }

    //Borrar copias caché antiguas
    $dirh = opendir($cache_dir);
    while (($file = readdir($dirh)) !== false) {
        if (strpos($file, $cache_prefix) === 0 && filemtime("$cache_dir/$file") < $last_modified) {
            //Evitar borrar archivos con la marca de tiempo correcta
            unlink("$cache_dir/$file");
        }
    }
    closedir($dirh);

    clearstatcache();

endif;

//Enviar fichero desde la caché
if ($encoding != 'none') {
    header("Content-Encoding: $encoding");
}
$fs = filesize($cache_file);
if ($fs !== false)
    header('Content-Length: ' . $fs);
if (readfile($cache_file) === false) {
    //Error
    header('HTTP/1.1 500 Internal Server Error');
    die('Error reading cache');
}