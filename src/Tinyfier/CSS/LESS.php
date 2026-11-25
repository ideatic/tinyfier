<?php

declare(strict_types=1);

/**
 * Process LESS code with wikimedia/less.php, adding some new functionality
 * (sprites, gradients, inline images, image filters and resizing)
 *
 * @phpstan-import-type CssFullSettings from Tinyfier_CSS_Tool
 * @phpstan-import-type OptimizerSettings from Tinyfier_Image_Optimizer
 */
class Tinyfier_CSS_LESS
{

    /**
     * @var CssFullSettings
     */
    private array $_settings;
    /**
     * @var array<string, Tinyfier_Image_Sprite>
     */
    private array $_sprites = [];

    /**
     * @param CssFullSettings $settings Tinyfier_CSS_Tool::process settings, with the defaults
     */
    public function process(string $str, array $settings): string
    {
        $this->_settings = $settings;

        // Preparar el compilador. less.php reescribe las url() relativas con la URL del fichero (relativeUrls)
        $parser = new Less_Parser([
            'compress'     => (bool)$settings['compress'],
            'relativeUrls' => true,
            'functions'    => [
                'gradient' => $this->lib_gradient(...),
                'sprite'   => $this->lib_sprite(...),
                'inline'   => $this->lib_inline(...),
                'filter'   => $this->lib_filter(...),
                'resize'   => $this->lib_resize(...),
            ],
        ]);

        // Con un fichero, los @import se buscan en su carpeta. Tinyfier_CSS_Tool pone '' por defecto en las rutas
        $absolute_path = (string)$settings['absolute_path'];
        $url_path = (string)$settings['url_path'];
        if ($absolute_path !== '') {
            $parser->parseFile($absolute_path, $url_path);
        } else {
            $parser->parse($str, $url_path !== '' ? $url_path : null);
        }

        if (!empty($settings['data']) && is_array($settings['data'])) {
            $parser->ModifyVars($settings['data']);
        }

        // Compilar. Las funciones lib_* se ejecutan aquí, y lib_sprite va añadiendo imágenes a $this->_sprites.
        // La lista se vacía siempre, para que un error no deje imágenes en la siguiente llamada
        try {
            $result = $parser->getCss();
            $sprites = $this->_sprites;
        } finally {
            $this->_sprites = [];
        }

        // Crear los sprites
        foreach ($sprites as $group => $sprite) {
            //Build sprite image
            $image = $sprite->build();

            //Save sprite
            $path = $this->_get_cache_path("sprite_$group", 'png');
            $image->save($path, $this->_lossy_quality(), $this->_optimize_images());
            $sprite_url = $this->_get_cache_url($path);

            //Replace sprite marks by the correct CSS
            foreach ($sprite->images() as $sprite_image) {
                $css = "url('$sprite_url') -{$sprite_image->left}px -{$sprite_image->top}px";
                $result = str_replace((string)$sprite_image->tag, $css, $result);
            }
        }

        return $result;
    }

    /**
     * Calidad para las imágenes con pérdida generadas (true = sin pérdida)
     */
    private function _lossy_quality(): bool|int
    {
        $quality = $this->_settings['lossy_quality'];
        return is_bool($quality) ? $quality : (int)$quality;
    }

    /**
     * Ajuste 'optimize_images': bool, o array de ajustes para Tinyfier_Image_Optimizer
     *
     * @return bool|OptimizerSettings
     */
    private function _optimize_images(): bool|array
    {
        $optimize = $this->_settings['optimize_images'];
        return is_array($optimize) ? $optimize : (bool)$optimize;
    }

    /**
     * Embeds the image in the stylesheet: inline('images/logo.png')
     */
    public function lib_inline(Less_Tree $arg): Less_Tree_Anonymous
    {
        $url = $this->_string($arg);

        if (!str_starts_with($url, 'data:')) {
            $local_path = $this->_local_path($url);
            $ext = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'gif', 'jpg', 'jpeg', 'webp', 'avif'], true)) {
                throw new InvalidArgumentException("The file extension '$ext' is not allowed for inline images");
            }
            $content = @file_get_contents($local_path);
            if ($content === false) {
                throw new RuntimeException("Can't retrieve $url content (looked in $local_path)");
            }
            $url = "data:image/$ext;base64," . base64_encode($content);
        }
        return new Less_Tree_Anonymous("url('$url')");
    }

    /**
     * Apply a filter on the selected image: filter('images/a.png', 'brightness', 50)
     * @see http://php.net/manual/function.imagefilter.php
     */
    public function lib_filter(Less_Tree ...$arguments): Less_Tree_Anonymous
    {
        // Primera cadena: imagen; segunda: filtro; números: argumentos del filtro
        $filter = $url = '';
        $filter_args = [];
        foreach ($arguments as $argument) {
            if ($argument instanceof Less_Tree_Dimension) {
                $filter_args[] = (int)$argument->value;
            } elseif ($url === '') {
                $url = $this->_string($argument);
            } else {
                $filter = $this->_string($argument);
            }
        }

        //Apply filter
        $image = new Tinyfier_Image_Tool($this->_local_path($url));
        $image->filter($filter, ...$filter_args);

        //Save image and generate CSS
        $format = in_array($image->format(), ['gif', 'png', 'jpg', 'webp', 'avif'], true) ? $image->format() : 'png';
        $path = $this->_get_cache_path('filter_' . $filter, $format);
        $image->save($path, $this->_lossy_quality(), $this->_optimize_images());
        return new Less_Tree_Anonymous("url('{$this->_get_cache_url($path)}')");
    }

    /**
     * Resize the selected image: resize('images/a.png', 50%) or resize('images/a.png', 100px, 50px, false)
     */
    public function lib_resize(Less_Tree $url_arg, Less_Tree_Dimension $width_arg, ?Less_Tree_Dimension $height_arg = null, ?Less_Tree $keep_aspect_arg = null): Less_Tree_Anonymous
    {
        //Get parameters
        $url = $this->_string($url_arg);
        $width = (float)$width_arg->value;
        $width_unit = $width_arg->unit->toString();
        $height = $height_arg === null ? null : (float)$height_arg->value;
        $height_unit = $height_arg === null ? 'px' : $height_arg->unit->toString();
        $keep_aspect = $keep_aspect_arg === null || strtolower($this->_string($keep_aspect_arg)) !== 'false';

        //Find local file
        $image = new Tinyfier_Image_Tool($this->_local_path($url));

        if ($width_unit == '%') {
            $width = round($image->width() * ($width / 100.0));
        }
        if ($height !== null && $height_unit == '%') {
            $height = round($image->height() * ($height / 100.0));
        }

        if ($width == $image->width() && $height == $image->height()) //Resize not necessary
        {
            return new Less_Tree_Anonymous("url('$url')");
        }

        $image->resize((int)$width, $height === null ? null : (int)$height, $keep_aspect);

        //Save image and generate CSS
        $format = in_array($image->format(), ['gif', 'png', 'jpg', 'webp', 'avif'], true) ? $image->format() : 'png';
        $path = $this->_get_cache_path('resize', $format);
        $image->save($path, $this->_lossy_quality(), $this->_optimize_images());
        return new Less_Tree_Anonymous("url('{$this->_get_cache_url($path)}')");
    }

    /**
     * Generates a gradient compatible with old browsers:
     * gradient('vertical', #fff, #888 50%, #000, 1px, 200px)
     */
    public function lib_gradient(Less_Tree ...$arguments): Less_Tree_Anonymous
    {
        $color_stops = [];
        $gradient_type = 'vertical';
        $gradient_width = 1;
        $gradient_height = 50;
        $size_changed = false;
        $colors_found = 0;

        //Get input parameters
        foreach ($arguments as $argument) {
            if ($argument instanceof Less_Tree_Color) { //Start or end color
                $color_stops[] = [$colors_found++ === 0 ? 0 : 100, '%', array_slice($argument->rgb, 0, 3)];
            } elseif ($argument instanceof Less_Tree_Expression && is_array($argument->value)) { //Color and position, in any order
                $color = $position = null;
                foreach ($argument->value as $part) {
                    if ($part instanceof Less_Tree_Color) {
                        $color = $part;
                    } elseif ($part instanceof Less_Tree_Dimension) {
                        $position = $part;
                    }
                }
                if ($color === null || $position === null) {
                    throw new InvalidArgumentException('Invalid gradient color stop: ' . $argument->toCSS());
                }
                $color_stops[] = [(float)$position->value, $position->unit->toString(), array_slice($color->rgb, 0, 3)];
            } elseif ($argument instanceof Less_Tree_Dimension) { //Image size (first time received: width, other times: height)
                if (!$size_changed) {
                    if ($gradient_type == 'vertical') //If the gradient is vertical, we only need the height parameter
                    {
                        $gradient_height = (int)$argument->value;
                    } else {
                        $gradient_width = (int)$argument->value;
                    }
                    $size_changed = true;
                } else {
                    if ($gradient_type == 'vertical') //If the gradient is vertical and we have two parameters, restore width parameter
                    {
                        $gradient_width = $gradient_height;
                    }
                    $gradient_height = (int)$argument->value;
                }
            } else { //Gradient type
                $gradient_type = strtolower($this->_string($argument));
                if ($gradient_type == 'vertical' && !$size_changed) {
                    $gradient_width = 1;
                    $gradient_height = 50;
                } elseif ($gradient_type == 'horizontal' && !$size_changed) {
                    $gradient_width = 50;
                    $gradient_height = 1;
                }
            }
        }

        //Generate gradient
        $back_color = null;
        $image = Tinyfier_Image_Gradient::generate($gradient_width, $gradient_height, $color_stops, $gradient_type, false, $back_color);
        $path = $this->_get_cache_path('gradient', 'png');
        $image->save($path, 100, $this->_optimize_images()); //Save gradients at maximum quality to avoid color loss
        //Create CSS code
        $color_positions = [];
        foreach ($color_stops as $stop) {
            [$position, $unit, $color] = $stop;
            $color = Tinyfier_CSS_Color::create($color);
            $color_positions[] = ($color->a == 1 ? $color->to_hex() : $color->to_rgb()) . " {$position}$unit";
        }
        $color_positions = implode(',', $color_positions);

        $back_color = Tinyfier_CSS_Color::create($back_color ?? [0, 0, 0])
                                        ->to_hex();

        if (in_array($gradient_type, ['vertical', 'horizontal', 'diagonal'], true)) {
            [$repeat, $position] = match ($gradient_type) {
                'vertical'   => ['repeat-x', 'to bottom'],
                'horizontal' => ['repeat-y', 'to right'],
                'diagonal'   => ['', '-45deg'],
            };
            $css = "background-repeat: $repeat;
background-image: url('{$this->_get_cache_url($path)}'); /* Old browsers */
background-image: linear-gradient($position, $color_positions);";
        } else {
            if ($gradient_type == 'radial') {
                $css = "background-image: url('{$this->_get_cache_url($path)}'); /* Old browsers */
background-image: radial-gradient(ellipse at center, $color_positions);";
            } else { //It is necessary to use images
                $css = "background-image: url('{$this->_get_cache_url($path)}');";
            }
        }

        // El valor cierra la declaración actual y añade otras (background: <color>; background-image: ...)
        return new Less_Tree_Anonymous("$back_color;$css");
    }

    /**
     * Create an image sprite: sprite('images/a.png', 'group')
     */
    public function lib_sprite(Less_Tree $url_arg, Less_Tree $group_arg): Less_Tree_Anonymous
    {
        //Get parameters
        $url = $this->_string($url_arg);
        $group = $this->_string($group_arg);

        //Get sprite
        $this->_sprites[$group] ??= new Tinyfier_Image_Sprite();

        //Add image to sprite. La marca se sustituye por la posición en process(), cuando el sprite está completo
        $file = $this->_local_path($url);
        $mark = 'CSSSPRITE_' . $group . '_' . md5($file);

        $this->_sprites[$group]->add_image($file, $mark);
        return new Less_Tree_Anonymous($mark);
    }

    /**
     * Texto de un argumento de less.php: cadena, palabra clave o url(), sin comillas
     */
    private function _string(Less_Tree $node): string
    {
        return match (true) {
            $node instanceof Less_Tree_Quoted, $node instanceof Less_Tree_Keyword => (string)$node->value,
            $node instanceof Less_Tree_Url                                       => $this->_string($node->value),
            default                                                              => $this->_remove_quotes(trim($node->toCSS())),
        };
    }

    /**
     * Convert a document URL into a local path
     */
    private function _local_path(string $url): string
    {
        //Remove url() if found
        if (stripos($url, 'url(') === 0) {
            $url = $this->_remove_quotes(substr($url, 4, -1));
        }

        if (strcasecmp($url, $this->_get_cache_url(basename($url))) === 0) {
            $path = "{$this->_settings['cache_path']}/" . basename($url);
        } else {
            $path = $url;
            if (str_starts_with($url, '/')) { //Relative to SCRIPT_FILENAME
                $path = realpath(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), 2) . $url);

                if (!$path) { //Relative to DOCUMENT_ROOT
                    $path = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') . $url);
                }
            } elseif (!str_starts_with($url, 'http://')) { //Relative to the document
                $path = realpath(dirname((string)$this->_settings['absolute_path']) . '/' . $url);
            }
        }

        if (!$path) {
            throw new Exception("Image $url not found");
        }

        return $path;
    }

    /**
     * Generate the path for a new cache file
     */
    private function _get_cache_path(string $suffix = '', string $extension = 'png'): string
    {
        static $cache_prefix, $i = 0;
        if (!isset($cache_prefix)) {
            $cache_prefix = "{$this->_settings['cache_path']}/"
                            . basename((string)$this->_settings['url_path'], '.css') . '_'
                            . substr(md5($this->_settings['absolute_path'] . serialize($this->_settings['data'])), 0, 5);
        }
        return $cache_prefix . ($i++) . "_$suffix.$extension";
    }

    /**
     * Get the external URL for a file in cache folder
     */
    private function _get_cache_url(string $filename = ''): string
    {
        return $this->_settings['cache_url'] . basename($filename);
    }

    /**
     * Remove quotes from beginning and end of the string
     */
    private function _remove_quotes(string $str): string
    {
        if (preg_match('/^("|\').*?\1$/', $str)) {
            return substr($str, 1, -1);
        }
        return $str;
    }

}
