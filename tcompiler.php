<?php

/**
 * Interfaz de línea de comandos para compilar archivos CSS y JS utilizando Tinyfier
 * 
 * Ejemplo: tcompiler estilo.css estilo2.css > estilo.min.css
 */
array_shift($argv); // Eliminar nombre del fichero de los argumentos de entrada

$files = array();
foreach ($argv as $argument) {
    $files[] = realpath($argument);
}
$_GET['f']=implode(',', $files);

$cache_path = 'tinyfier-resources';
if (!is_dir($cache_path))
    mkdir($cache_path);

require 'tinyfier.php';