<?php

require_once 'lessc.inc.php';

/**
 * Process a .less file with lessphp library, adding some new functionality
 */
class tinyfier_less extends lessc {

    /**
     * @var lessc 
     */
    private $_settings;
    private $_sprites = array();

    public function process($str = NULL, $settings = NULL) {
        $this->_sprites = array();
        $this->_settings = $settings;

        //Prepare compiler

        $this->registerFunction('gradient', array($this, 'lib_gradient'));
        $this->registerFunction('sprite', array($this, 'lib_sprite'));
        $this->registerFunction('inline', array($this, 'lib_inline'));
        $this->registerFunction('url', array($this, 'lib_url'));
        $this->registerFunction('filter', array($this, 'lib_filter'));

        if ($settings['compress']) {
            $this->setFormatter('compressed');
            $this->setPreserveComments(false);
        } else {
            $this->setFormatter('classic');
            $this->setPreserveComments(true);
        }


        if (!empty($settings['data']))
            $this->setVariables($settings['data']);

        if (isset($settings['absolute_path']))
            $this->addImportDir(dirname($settings['absolute_path']));

        //Compile with lessphp
        $result = isset($str) ? $this->compile($str) : $this->compileFile($settings['absolute_path']);

        //Finalize and create sprites
        foreach ($this->_sprites as $group => $sprite) {
            /* @var $sprite gd_sprite */
            //Build sprite image
            $image = $sprite->build();

            //Save (only if not equal!)
            $path = $this->_get_cache_path("sprite_$group", 'png');
            $image->save($path, 'png', TRUE);
            $sprite_url = $this->_get_cache_url($path);

            //Replace sprite marks by the correct CSS
            foreach ($sprite->images() as $sprite_image) {
                /* @var $sprite_image gd_sprite_image */
                $css = "url('$sprite_url') -{$sprite_image->left}px -{$sprite_image->top}px";
                $result = str_replace($sprite_image->tag, $css, $result);
            }
        }

        return $result;
    }

    /**
     * Rewrite URLs in the document for put them right
     */
    public function lib_url($arg) {
        list($type, $dummy, $value) = $arg;
        $url = $this->_remove_quotes(trim($value[0]));

        if (strpos($url, 'data:') !== 0 && !empty($url)) { //Don't rewrite embedded images
            if ($url[0] != '/' && strpos($url, 'http://') !== 0) { //Rewrite relative URL from tinyfier script path
                if ($this->_settings['relative_path'][0] == '/') {
                    $url = $this->_clear_path(dirname($this->_settings['relative_path']) . '/' . $url);
                } else {
                    $url = $this->_clear_path(dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/' . dirname($this->_settings['relative_path']) . '/' . $url);
                }
            }
        }

        return array($type, '', array("url('$url')"));
    }

    /**
     * Embeds the image in the stylesheet
     */
    public function lib_inline($arg) {
        list($type, $dummy, $value) = $arg;
        $url = $this->_remove_quotes(trim($value[0]));

        if (strpos($url, 'data:') !== 0) {
            $local_path = $this->_local_url($url);
            $ext = pathinfo($local_path, PATHINFO_EXTENSION);
            if (!in_array($ext, array('png', 'gif', 'jpg', 'jpeg'))) {
                die("The file extension '$ext' is not allowed for inline images");
            }
            $content = @file_get_contents($local_path) or die("Can't retrieve $url content (looked in $local_path)");
            $url = "data:image/$ext;base64," . base64_encode($content);
        }
        return array($type, '', array("url('$url')"));
    }

    /**
     * Generate a desaturate version for the image argument
     */
    public function lib_filter($arguments) {
        //Process input arguments
        $filter = $url = '';
        $filter_args = array();
        foreach ($arguments[2] as $argument) {
            switch ($argument[0]) {
                case 'string':
                    $value = $this->_remove_quotes(trim($argument[2][0]));
                    if (empty($url))
                        $url = $value;
                    else
                        $filter = $value;
                    break;

                case 'number':
                    $filter_args[] = $argument[1];
                    break;
            }
        }

        //Find local file
        $local_path = $this->_local_url($url);
        
        //Apply filter
        require_once 'gd/gd_image.php';
        $image = new gd_image($local_path);
        call_user_func_array(array($image, 'filter'), array_merge(array($filter), $filter_args));

        //Save image and generate CSS
        $format = in_array($image->format, array('gif', 'png', 'jpg')) ? $image->format : 'png';
        $path = $this->_get_cache_path('filter_' . $filter, $format);
        $image->save($path, $format, TRUE);
        return array('string', '', array("url('{$this->_get_cache_url($path)}')"));
    }

    /**
     * Generates a gradient compatible with old browsers
     */
    public function lib_gradient($arguments) {
        $color_stops = array();
        $gradient_type = 'vertical';
        $gradient_width = 1;
        $gradient_height = 50;
        $size_changed = FALSE;

        //Get input parameters
        foreach ($arguments[2] as $argument) {
            $type = $argument[0];
            switch ($type) {
                case 'raw_color':
                case 'color': //Start or end color  
                    $argument = $this->coerceColor($argument);
                    $is_initial_color = isset($is_initial_color) ? FALSE : TRUE;
                    // $color = $type == 'color' ? array($argument[1], $argument[2], $argument[3]) : $this->coerceColor($argument[1]);
                    $color_stops[] = array($is_initial_color ? 0 : 100, '%', array($argument[1], $argument[2], $argument[3]));
                    break;
                case 'list':
                    $list_data = $argument[2];
                    if ($list_data[0][0] == 'color' || $list_data[0][0] == 'raw_color') { //Color and position
                        $color_index = 0;
                        $position_index = 1;
                        $list_data[$color_index] = $this->coerceColor($list_data[$color_index]);
                    } else { //Position and color
                        $color_index = 1;
                        $position_index = 0;
                    }
                    $color = array($list_data[$color_index][1], $list_data[$color_index][2], $list_data[$color_index][3]);
                    $position = $list_data[$position_index][1];
                    $unit = $list_data[$position_index][2];
                    $color_stops[] = array($position, $unit, $color);
                    break;
                case 'string': //Gradient type
                    $gradient_type = strtolower($this->_remove_quotes($argument[2][0]));
                    if ($gradient_type == 'vertical' && !$size_changed) {
                        $gradient_width = 1;
                        $gradient_height = 50;
                    } else if ($gradient_type == 'horizontal' && !$size_changed) {
                        $gradient_width = 50;
                        $gradient_height = 1;
                    }
                    break;
                case 'number': //Image size (first time received: width, other times: height)
                    if (!$size_changed) {
                        if ($gradient_type == 'vertical') //If the gradient is vertical, we only need the height parameter
                            $gradient_height = $argument[1];
                        else
                            $gradient_width = $argument[1];
                        $size_changed = TRUE;
                    } else {
                        if ($gradient_type == 'vertical') //If the gradient is vertical and we have two parameters, restore width parameter
                            $gradient_width = $gradient_height;
                        $gradient_height = $argument[1];
                    }
                    break;
            }
        }

        //Generate gradient
        require_once 'gd/gd_gradients.php';
        require_once 'gd/gd_image.php';
        $gd = new gd_gradients();
        // var_dump($arguments,$gradient_width, $gradient_height, $color_stops, $gradient_type, FALSE, $back_color);die;
        $image = $gd->generate_gradient($gradient_width, $gradient_height, $color_stops, $gradient_type, FALSE, $back_color);
        $path = $this->_get_cache_path('gradient', 'png');
        $image->save($path, 'png', TRUE);

        //Create CSS code
        $css_color_positions = array();
        foreach ($color_stops as $stop) {
            list($position, $unit, $color) = $stop;

            $color = $this->_css_color($color);
            $css_color_positions[] = "$color {$position}$unit";
        }
        $css_color_positions = implode(',', $css_color_positions);

        $back_color = $this->_css_color($back_color);

        if (in_array($gradient_type, array('vertical', 'horizontal', 'diagonal'))) {
            switch ($gradient_type) {
                case 'vertical':
                    $repeat = 'repeat-x';
                    $position = 'top';
                    break;

                case 'horizontal':
                    $repeat = 'repeat-y';
                    $position = 'left';
                    break;

                case 'diagonal':
                    $repeat = '';
                    $position = '-45deg';
                    break;

                default:
                    $repeat = '';
                    $position = $gradient_type;
                    break;
            }
            $css = "background: url('{$this->_get_cache_url($path)}') $repeat $back_color; /* Old browsers */
background-image: linear-gradient($position, $css_color_positions);";
        } else if ($gradient_type == 'radial') {
            $css = "background: url('{$this->_get_cache_url($path)}') no-repeat $back_color; /* Old browsers */
background-image: radial-gradient(center, ellipse cover, $css_color_positions);";
        } else { //It is necessary to use images
            $css = "background: url('{$this->_get_cache_url($path)}') $back_color;";
        }

        return array('string', '', array(substr($css, 11))); //Remove the first "background:"
    }

    /**
     * Create an image sprite
     */
    public function lib_sprite($arg) {
        //Get parameters
        $url = $this->_remove_quotes(trim($arg[2][0][2][0]));
        $group = $this->_remove_quotes(trim($arg[2][1][2][0]));


        //Get sprite
        require_once 'gd/gd_sprite.php';
        require_once 'gd/gd_image.php';
        if (!isset($this->_sprites[$group])) {
            $this->_sprites[$group] = new gd_sprite();
        }

        //Add image to sprite
        $file = $this->_local_url($url);
        $mark = 'CSSSPRITE_' . $group . '_' . md5($file);

        $this->_sprites[$group]->add_image($file, $mark);
        return array('string', '', array($mark));
    }

    /**
     * Convert a document URL into a local path
     * @return string
     */
    private function _local_url($url) {
        if ($url[0] == '/') { //Relative to DOCUMENT_ROOT
            $url = realpath($_SERVER['DOCUMENT_ROOT'] . $url);
        } elseif (strpos($url, 'http://') !== 0) { //Relative to the document
            $url = realpath(dirname($this->_settings['absolute_path']) . '/' . $url);
        }
        return $url;
    }

    /**
     * Generate the path for a new cache file
     * @param string $suffix
     * @return string
     */
    private function _get_cache_path($suffix = '', $extension = '.png') {
        static $cache_prefix, $i = 0;
        if (!isset($cache_prefix)) {
            $cache_prefix = $this->_settings['cache_path'] . '/' . basename($this->_settings['relative_path'], '.css') . '_' . substr(md5($this->_settings['absolute_path'] . serialize($this->_settings['data'])), 0, 5);
        }
        return $cache_prefix . ($i++) . "_$suffix.$extension";
    }

    /**
     * Get the external URL for a file in cache folder
     * @param string $filename
     * @return string
     */
    private function _get_cache_url($filename = '') {
        return dirname($_SERVER['SCRIPT_NAME']) . '/cache/' . basename($filename);
    }

    private function _clear_path($path) {
        // /cool/yeah/../zzz ==> /cool/zzz
        $path = preg_replace('/\w+\/\.\.\//', '', $path);

        // bla/./bloo ==> bla/bloo
        // bla//bloo ==> bla/bloo
        $path = str_replace(array('/./', '////', '///', '//'), '/', $path);

        return $path;
    }

    /**
     * Remove quotes from beginning and end of the string
     * @return string
     */
    private function _remove_quotes($str) {
        if (preg_match('/^("|\').*?\1$/', $str))
            return substr($str, 1, -1);
        return $str;
    }

    /**
     * Convierte un color en varios formatos de entrada a un color CSS de la forma #RRGGBB
     */
    private function _css_color($color) {
        if (is_array($color)) {
            $hex = '';
            for ($i = 0; $i < count($color); $i++) {
                $h = dechex($color[$i]);
                $hex .= (strlen($h) < 2 ? '0' : '') . $h;
            }
            return "#$hex";
        } else if ($color[0] != '#') {
            return "#$color";
        } else {
            return $color;
        }
    }

}