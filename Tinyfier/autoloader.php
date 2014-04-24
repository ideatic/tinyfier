<?php

function tinyfier_autoload($class) {
    $file = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . str_replace('_', DIRECTORY_SEPARATOR, $class).'.php';

    
    if (file_exists($file)) {
        require $file;
    }
}

spl_autoload_register('tinyfier_autoload');
