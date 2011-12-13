<?php

require 'lessc.inc.php';

class tinyfier_less extends lessc {

    private $_settings;
    private $_cache_prefix;
    private $_sprites = array();

    public function __construct($settings) {
        parent::__construct();
        $this->_settings = $settings;
        $this->_cache_prefix = basename($this->_settings['relative_path'], '.css') . '_' . substr(md5($this->_settings['absolute_path'].  serialize($this->_settings['data'])), 0, 5);
    }

    public function parse($str = null, $initial_variables = null) {
        $this->_sprites = array();

        //Process with lessphp base class
        $result = parent::parse($str, $initial_variables);

        //Finalize and create sprites
        $replacements = array();
        foreach ($this->_sprites as $group => $sprite) {
            /* @var $sprite gd_sprite */
            //Build sprite image
            $image = $sprite->build();

            //Save (only if not equal!)
            $file_name = $this->_cache_prefix . '_sprite_' . $group . '.png';
            $path = $this->_settings['cache_path'] . '/' . $file_name;
            save_image_png($image, $path, true);
            $sprite_url = $this->_get_cache_url($file_name);

            //Replace sprite marks by the correct CSS
            foreach ($sprite->images() as $sprite_image) {
                /* @var $sprite_image gd_sprite_image */
                $css = "url('$sprite_url') -{$sprite_image->Left}px -{$sprite_image->Top}px";
                $result = str_replace($sprite_image->Tag, $css, $result);
            }
        }

        return $result;
    }

    /**
     * Rewrite URLs in the document for put them right
     */
    protected function lib_url($arg) {
        list($type, $value) = $arg;
        $url = $this->_remove_quotes(trim($value));

        if (strpos($url, 'data:') !== 0) {//Don't rewrite embedded images
            if ($url[0] != '/' && strpos($url, 'http://') !== 0) { //Rewrite relative URL from tinyfier script path
                if ($this->_settings['relative_path'][0] == '/') {
                    $url = $this->_clear_path(dirname($this->_settings['relative_path']) . '/' . $url);
                } else {
                    $url = $this->_clear_path(dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/' . dirname($this->_settings['relative_path']) . '/' . $url);
                }
            }
        }

        return array($type, "url('$url')");
    }

    /**
     * Embeds the image in the stylesheet
     */
    protected function lib_inline($arg) {
        list($type, $value) = $arg;
        $url = $this->_remove_quotes(trim($value));

        if (strpos($url, 'data:') !== 0) {
            $local_path = $this->_local_url($url);
            $content = @file_get_contents($local_path) or die("Can't retrieve $url content (looked in $local_path)");
            $url = 'data:image/' . pathinfo($local_path, PATHINFO_EXTENSION) . ';base64,' . base64_encode($content);
        }
        return array($type, "url('$url')");
    }

    /**
     * Generates a gradient compatible with old browsers
     */
    protected function lib_gradient($arguments) {
        static $i = 0;
        
        $gradient_colors = array();
        $gradient_type = 'vertical';
        $gradient_width = 1;
        $gradient_height = 50;
        $size_changed = false;

        //Get input parameters
        foreach ($arguments[2] as $argument) {
            $type = $argument[0];
            switch ($type) {
                case 'color'://Start or end color
                    $gradient_colors[isset($gradient_colors[0]) ? 100 : 0] = array($argument[1], $argument[2], $argument[3]);
                    break;
                case 'list':
                    if ($argument[2][0][0] == '%') {//Position and color
                        $gradient_colors[$argument[2][0][1]] = array($argument[2][1][1], $argument[2][1][2], $argument[2][1][3]);
                    } else { //Color and position
                        $gradient_colors[$argument[2][1][1]] = array($argument[2][0][1], $argument[2][0][2], $argument[2][0][3]);
                    }
                    break;
                case 'string'://Gradient type
                    $gradient_type = strtolower($this->_remove_quotes($argument[1]));
                    if ($gradient_type == 'vertical' && !$size_changed) {
                        $gradient_width = 1;
                        $gradient_height = 50;
                    } else if ($gradient_type == 'horizontal' && !$size_changed) {
                        $gradient_width = 50;
                        $gradient_height = 1;
                    }
                    break;
                case 'px'://Image size
                    if (!$size_changed) {
                        $gradient_width = $argument[1];
                        $size_changed = true;
                    } else {
                        $gradient_height = $argument[1];
                    }
                    break;
            }
        }

        //Generate gradient
        require_once 'gd/gd_gradients.php';
        require_once 'gd/gd_helpers.php';
        $gd = new gd_gradients();
        $image = $gd->generate_gradient($gradient_width, $gradient_height, $gradient_colors, $gradient_type);
        $file_name = $this->_cache_prefix . '_gradient_' . ($i++) . '.png';
        save_image_png($image, $this->_settings['cache_path'] . '/' . $file_name);

        //Create CSS code
        $color_positions_w3c = array();
        $color_positions_webkit = array();
        foreach ($gradient_colors as $position => $color) {
            $color = $this->_css_color($color);
            $color_positions_w3c[] = "$color $position%";
            $color_positions_webkit[] = "color-stop($position%,$color)";
        }
        $color_positions_w3c = implode(',', $color_positions_w3c);
        $color_positions_webkit = implode(',', $color_positions_webkit);

        $colors_positions = array_keys($gradient_colors);
        $end_color = $this->_css_color($gradient_colors[end($colors_positions)]);

        if (in_array($gradient_type, array('vertical', 'horizontal', 'diagonal'))) {
            switch ($gradient_type) {
                case 'vertical':
                    $repeat = 'repeat-x';
                    $position = 'top';
                    $webkit_position = 'left top, left bottom';
                    break;

                case 'horizontal':
                    $repeat = 'repeat-y';
                    $position = 'left';
                    $webkit_position = 'left top, right top';
                    break;

                case 'diagonal':
                    $repeat = '';
                    $position = '45deg';
                    $webkit_position = 'left top, right  bottom';
                    break;

                default:
                    $repeat = '';
                    $position = $webkit_position = $gradient_type;
                    break;
            }
            $css = "background: url('{$this->_get_cache_url($file_name)}') $repeat $end_color; /* Old browsers */
background: -moz-linear-gradient($position, $color_positions_w3c); /* FF3.6+ */
background: -webkit-gradient(linear, $webkit_position, $color_positions_webkit); /* Chrome,Safari4+ */
background: -webkit-linear-gradient($position, $color_positions_w3c); /* Chrome10+,Safari5.1+ */
background: -o-linear-gradient($position, $color_positions_w3c); /* Opera11.10+ */
background: -ms-linear-gradient($position, $color_positions_w3c); /* IE10+ */
background: linear-gradient($position, $color_positions_w3c); /* W3C */";
        } else if ($gradient_type == 'radial') {
            $css = "background: url('{$this->_get_cache_url($file_name)}') $repeat $end_color; /* Old browsers */
background: -moz-radial-gradient($color_positions_w3c); /* FF3.6+ */
background: -webkit-gradient(radial, $color_positions_webkit); /* Webkit */
background: -o-radial-gradient($color_positions_w3c); 
background: -ms-radial-gradient($color_positions_w3c); 
background: radial-gradient($color_positions_w3c);";
        } else {//It is necessary to use images
            $css = "background: url('{$this->_get_cache_url($file_name)}') $end_color;";
        }

        return array('string', substr($css, 11)); //Remove the first "background:"
    }

    /**
     * Create an image sprite
     */
    protected function lib_sprite($arg) {
        //Get parameters
        $url = $this->_remove_quotes(trim($arg[2][0][1]));
        $group = $this->_remove_quotes(trim($arg[2][1][1]));

        //Get sprite
        require_once 'gd/gd_sprite.php';
        require_once 'gd/gd_helpers.php';
        if (!isset($this->_sprites[$group])) {
            $this->_sprites[$group] = new gd_sprite();
        }

        //Add image to sprite
        $file = $this->_local_url($url);
        $mark = 'CSSSPRITE_' . $group . '_' . md5($file);

        $this->_sprites[$group]->add_image($file, $mark);
        return array('string', $mark);
    }

    /**
     * Convert a document URL into a local path
     * @return string
     */
    private function _local_url($url) {
        if ($url[0] == '/') { //Relative to DOCUMENT_ROOT
            $url = realpath($_SERVER['DOCUMENT_ROOT'] . $url);
        } elseif (strpos($url, 'http://') !== 0) {//Relative to the document
            $url = realpath(dirname($this->_settings['absolute_path']) . '/' . $url);
        }
        return $url;
    }

    /**
     * Get the external URL for a file in cache folder
     * @param string $filename
     * @return string
     */
    private function _get_cache_url($filename='') {
        return dirname($_SERVER['SCRIPT_NAME']) . '/cache/' . $filename;
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
                $hex .= ( strlen($h) < 2 ? '0' : '') . $h;
            }
            return "#$hex";
        } else if ($color[0] != '#') {
            return "#$color";
        } else {
            return $color;
        }
    }

}
