<?php

require 'lessc.inc.php';

class tinyfier_less extends lessc {

    private $_settings;
    private $_cache_prefix;
    private $_sprites = array();

    public function __construct($settings) {
        parent::__construct();
        $this->_settings = $settings;
        $this->_cache_prefix = basename($this->_settings['relative_path'], '.css') . '_' . substr(md5($this->_settings['absolute_path']), 0, 5);
    }

    public function parse($str = null, $initial_variables = null) {
        $this->_sprites = array();

        //Procesar código
        $result = parent::parse($str, $initial_variables);

        //Finalizar sprites y establecer sus valores
        $replacements = array();
        foreach ($this->_sprites as $group => $sprite) {
            //Construir imagen
            $image = $sprite->build();

            //Guardar
            $file_name = $this->_cache_prefix . '_sprite_' . $group . '.png';
            $path = $this->_settings['cache_path'] . '/' . $file_name;
            save_image_png($image, $path);

            //Reemplazar marcas por su correspondiente código CSS con la ruta del sprite
            foreach ($sprite->images() as $sprite_image) {
                $css = "url('cache/$file_name') {$sprite_image->Left}px {$sprite_image->Top}px";
                $result = str_replace($sprite_image->Tag, $css, $result);
            }
        }

        return $result;
    }

    /**
     * Reescribe las URLs del archivo CSS para que apunten a la dirección correcta
     */
    protected function lib_url($arg) {
        list($type, $value) = $arg;
        $url = $this->_remove_quotes(trim($value));

        if (strpos($url, 'data:') !== 0) {//No reescribir imágenes incrustadas
            if ($url[0] != '/' && strpos($url, 'http://') !== 0) { //Recalcular URL relativa respecto al script de tinyfier
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
     * Incrusta la imagen en la hoja de estilos
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
     * Genera un fondo degradado compatible con navegadores antiguos
     */
    protected function lib_gradient($arguments) {
        static $i = 0;

        $gradient_colors = array();
        $gradient_type = 'vertical';
        $gradient_width = 1;
        $gradient_height = 50;
        $size_changed = false;

        //Obtener argumentos de entrada
        foreach ($arguments[2] as $argument) {
            $type = $argument[0];
            switch ($type) {
                case 'color'://Color de inicio o final
                    $gradient_colors[isset($gradient_colors[0]) ? 100 : 0] = array($argument[1], $argument[2], $argument[3]);
                    break;
                case 'list':
                    if ($argument[2][0][0] == '%') {//Posición y color
                        $gradient_colors[$argument[2][0][1]] = array($argument[2][1][1], $argument[2][1][2], $argument[2][1][3]);
                    } else { //Color y posición 
                        $gradient_colors[$argument[2][1][1]] = array($argument[2][0][1], $argument[2][0][2], $argument[2][0][3]);
                    }
                    break;
                case 'string':
                    $gradient_type = strtolower($this->_remove_quotes($argument[1]));
                    if ($gradient_type == 'vertical' && !$size_changed) {

                        $gradient_width = 1;
                        $gradient_height = 50;
                    } else if ($gradient_type == 'horizontal' && !$size_changed) {
                        $gradient_width = 50;
                        $gradient_height = 1;
                    }
                    break;
                case 'px':
                    if (!$size_changed) {
                        $gradient_width = $argument[1];
                        $size_changed = true;
                    } else {
                        $gradient_height = $argument[1];
                    }
                    break;
            }
        }

        //Generar gradiente
        require_once '../gd_gradients.php';
        require_once '../gd_helpers.php';
        $gd = new gd_gradients();
        $image = $gd->generate_gradient($gradient_width, $gradient_height, $gradient_colors, $gradient_type);
        $file_name = $this->_cache_prefix . '_gradient_' . ($i++) . '.png';
        save_image_png($image, $this->_settings['cache_path'] . '/' . $file_name);

        //Crear códigos CSS 
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
                    $webkit_position = ' left top, right top';
                    break;

                case 'diagonal':
                    $repeat = '';
                    $position = '45deg';
                    $webkit_position = 'left top, right  bottom';
                    break;
            }
            $css = "background: url('tinyfier/cache/$file_name') $repeat $end_color; /* Old browsers */
background: -moz-linear-gradient($position, $color_positions_w3c); /* FF3.6+ */
background: -webkit-gradient(linear, $webkit_position, $color_positions_webkit); /* Chrome,Safari4+ */
background: -webkit-linear-gradient($position, $color_positions_w3c); /* Chrome10+,Safari5.1+ */
background: -o-linear-gradient($position, $color_positions_w3c); /* Opera11.10+ */
background: -ms-linear-gradient($position, $color_positions_w3c); /* IE10+ */
background: linear-gradient($position, $color_positions_w3c); /* W3C */";
        } else if ($gradient_type == 'radial') {
            $css = "background: url('tinyfier/cache/$file_name') $repeat $end_color; /* Old browsers */
background: -moz-radial-gradient($color_positions_w3c); /* FF3.6+ */
background: -webkit-gradient(radial, $color_positions_webkit); /* Webkit */
background: -o-radial-gradient($color_positions_w3c); 
background: -ms-radial-gradient($color_positions_w3c); 
background: radial-gradient($color_positions_w3c);";
        } else {//Es necesario usar imágenes
            $css = "background: url('tinyfier/cache/$file_name') $end_color;";
        }

        return array('string', substr($css, 11)); //Eliminar el comienzo "background:"
    }

    /**
     * Crea un sprite de imágenes para optimizar el número de peticiones realizadas al servidor
     */
    protected function lib_sprite($arg) {
        //Obtener parámetros
        $url = $this->_remove_quotes(trim($arg[2][0][1]));
        $group = $this->_remove_quotes(trim($arg[2][1][1]));

        //Crear sprite
        require_once '../gd_sprite.php';
        require_once '../gd_helpers.php';
        if (!isset($this->_sprites[$group]))
            $this->_sprites[$group] = new gd_sprite();

        //Añadir imagen al sprite
        $file = $this->_local_url($url);
        $mark = 'CSSSPRITE_' . $group . '_' . md5($file);

        $this->_sprites[$group]->add_image($file, $mark);
        return array('string', $mark);
    }

    /**
     * Convierte una url del documento CSS en una ruta local
     * @return string
     */
    private function _local_url($url) {
        //Convertir URL en ruta local
        if ($url[0] == '/') { //Url relativa a DOCUMENT_ROOT
            $url = realpath($_SERVER['DOCUMENT_ROOT'] . $url);
        } elseif (strpos($url, 'http://') !== 0) {//Url relativa al fichero css
            $url = realpath(dirname($this->_settings['path']) . '/' . $url);
        }
        return $url;
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
     * Elimina las comillas de comienzo y final de la cadena especificada, si existen
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
