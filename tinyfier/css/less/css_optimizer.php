<?php

require_once 'lessc.inc.php';

/**
 * Custom lessphp extension for optimize css code
 */
class css_optimizer {

    private $_settings;

    public function __construct($file, $settings) {
        parent::__construct($file);
        $this->_settings = $settings;
    }

    public function optimize($css) {
        $parser = new lessc_parser($this, $name);
        $root = $parser->parse($str);
    }

}
