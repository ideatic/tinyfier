<?php

declare(strict_types=1);

/**
 * Process a .less file with lessphp library, adding some new functionality
 *
 * @phpstan-import-type CssFullSettings from Tinyfier_CSS_Tool
 * @phpstan-import-type OptimizerSettings from Tinyfier_Image_Optimizer
 */
class Tinyfier_CSS_LESS extends lessc
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

        //Prepare compiler
        $this->registerFunction('gradient', $this->lib_gradient(...));
        $this->registerFunction('sprite', $this->lib_sprite(...));
        $this->registerFunction('inline', $this->lib_inline(...));
        $this->registerFunction('url', $this->lib_url(...));
        $this->registerFunction('filter', $this->lib_filter(...));

        if ((bool)$settings['compress']) {
            $this->setFormatter('compressed');
            $this->setPreserveComments(false);
        } else {
            $this->setFormatter('classic');
            $this->setPreserveComments(true);
        }


        if (!empty($settings['data']) && is_array($settings['data'])) {
            $this->setVariables($settings['data']);
        }

        // Tinyfier_CSS_Tool pone '' por defecto en absolute_path, así que se comprueba que no esté vacío
        $absolute_path = (string)($settings['absolute_path'] ?? '');
        if ($absolute_path !== '') {
            $this->addImportDir(dirname($absolute_path));
        }

        //Compile with lessphp
        $result = $absolute_path !== '' ? $this->compileFile($absolute_path) : $this->compile($str);

        //Finalize and create sprites (lib_sprite los fue añadiendo durante la compilación)
        $sprites = $this->_sprites;
        $this->_sprites = [];
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
     * Rewrite URLs in the document for put them right
     *
     * @param array<int, mixed> $arg lessphp value
     *
     * @return array<int, mixed>
     */
    public function lib_url(array $arg): array
    {
        [$type, $dummy, $value] = $arg;
        $url = $rewrited = $this->_remove_quotes(trim($value[0]));

        $rewrite = !str_starts_with($url, 'data:') //Don't rewrite data URLs
                   && !empty($url) //Don't rewrite empty URLs
                   && $url[0] != '/' && !str_contains($url, '://') //Don't rewrite site root–relative or absolute URLs
        ;


        if ($rewrite) {
            $url_path = (string)$this->_settings['url_path'];
            if (str_starts_with($url_path, '/') || !str_starts_with($url_path, '://')) {
                $rewrited = $this->_clear_path(dirname($url_path) . '/' . $url);
            } else {
                //Calculate working url
                $working = (string)$_SERVER['REQUEST_URI'];
                $ending = $_SERVER['PATH_INFO'] ?? '';
                $ending = $_SERVER['ORIG_PATH_INFO'] ?? $ending;

                if (!empty($_SERVER['QUERY_STRING'])) {
                    $ending .= '?' . $_SERVER['QUERY_STRING'];
                }

                if (str_ends_with($working, $ending)) {
                    $working = substr($working, 0, -strlen($ending));
                }
                $working = rtrim($working, '/');

                $rewrited = $this->_clear_path(dirname($working) . '/../' . dirname($url_path) . '/' . $url);
            }
        }

        return [$type, '', ["url('$rewrited')"]];
    }

    /**
     * Embeds the image in the stylesheet
     *
     * @param array<int, mixed> $arg lessphp value
     *
     * @return array<int, mixed>
     */
    public function lib_inline(array $arg): array
    {
        [$type, $dummy, $value] = $arg;
        $url = $this->_remove_quotes(trim($value[0]));

        if (!str_starts_with($url, 'data:')) {
            $local_path = $this->_local_path($url);
            $ext = pathinfo($local_path, PATHINFO_EXTENSION);
            if (!in_array($ext, ['png', 'gif', 'jpg', 'jpeg', 'webp', 'avif'], true)) {
                throw new InvalidArgumentException("The file extension '$ext' is not allowed for inline images");
            }
            $content = @file_get_contents($local_path);
            if ($content === false) {
                throw new RuntimeException("Can't retrieve $url content (looked in $local_path)");
            }
            $url = "data:image/$ext;base64," . base64_encode($content);
        }
        return [$type, '', ["url('$url')"]];
    }

    /**
     * Apply a filter on the selected image
     * @see http://php.net/manual/function.imagefilter.php
     *
     * @param array<int, mixed> $arguments lessphp value
     *
     * @return array<int, mixed>
     */
    public function lib_filter(array $arguments): array
    {
        //Process input arguments
        $filter = $url = '';
        $filter_args = [];
        foreach ($arguments[2] as $argument) {
            switch ($argument[0]) {
                case 'string':
                    $value = $this->_remove_quotes(trim($argument[2][0]));
                    if (empty($url)) {
                        $url = $value;
                    } else {
                        $filter = $value;
                    }
                    break;

                case 'number':
                    $filter_args[] = (int)$argument[1];
                    break;
            }
        }

        //Find local file
        $local_path = $this->_local_path($url);

        //Apply filter
        $image = new Tinyfier_Image_Tool($local_path);
        $image->filter($filter, ...$filter_args);

        //Save image and generate CSS
        $format = in_array($image->format(), ['gif', 'png', 'jpg', 'webp', 'avif'], true) ? $image->format() : 'png';
        $path = $this->_get_cache_path('filter_' . $filter, $format);
        $image->save($path, $this->_lossy_quality(), $this->_optimize_images());
        return ['string', '', ["url('{$this->_get_cache_url($path)}')"]];
    }

    /**
     * Resize the selected image
     *
     * @param array<int, mixed> $arg lessphp value
     *
     * @return array<int, mixed>
     */
    public function lib_resize(array $arg): array
    {
        //Get parameters
        $url = $this->_remove_quotes(trim($arg[2][0][2][0]));
        // lessphp entrega los números como int o float: se pasan a texto para limpiar comillas
        $width = (float)$this->_remove_quotes(trim((string)$arg[2][1][1]));
        $width_unit = trim($arg[2][1][2]);
        $height = isset($arg[2][2]) ? (float)$this->_remove_quotes(trim((string)$arg[2][2][1])) : null;
        $height_unit = isset($arg[2][2]) ? trim($arg[2][2][2]) : 'px';
        $keep_aspect = !isset($arg[2][3]) || strtolower($this->_remove_quotes(trim((string)$arg[2][3][1]))) !== 'false';


        //Find local file
        $local_path = $this->_local_path($url);

        //Apply filter
        $image = new Tinyfier_Image_Tool($local_path);

        if ($width_unit == '%') {
            $width = round($image->width() * ($width / 100.0));
        }
        if ($height !== null && $height_unit == '%') {
            $height = round($image->height() * ($height / 100.0));
        }

        if ($width == $image->width() && $height == $image->height()) //Resize not necessary
        {
            return ['string', '', ["url('$url')"]];
        }

        $image->resize((int)$width, $height === null ? null : (int)$height, $keep_aspect);

        //Save image and generate CSS
        $format = in_array($image->format(), ['gif', 'png', 'jpg', 'webp', 'avif'], true) ? $image->format() : 'png';
        $path = $this->_get_cache_path('resize', $format);
        $image->save($path, $this->_lossy_quality(), $this->_optimize_images());
        return ['string', '', ["url('{$this->_get_cache_url($path)}')"]];
    }

    /**
     * Generates a gradient compatible with old browsers
     *
     * @param array<int, mixed> $arguments lessphp value
     *
     * @return array<int, mixed>
     */
    public function lib_gradient(array $arguments): array
    {
        $color_stops = [];
        $gradient_type = 'vertical';
        $gradient_width = 1;
        $gradient_height = 50;
        $size_changed = false;

        //Get input parameters
        foreach ($arguments[2] as $argument) {
            $type = $argument[0];
            switch ($type) {
                case 'raw_color':
                case 'color': //Start or end color  
                    $argument = $this->coerceColor($argument) ?? throw new InvalidArgumentException('Invalid gradient color');
                    $is_initial_color = !isset($is_initial_color);
                    // $color = $type == 'color' ? array($argument[1], $argument[2], $argument[3]) : $this->coerceColor($argument[1]);
                    $color_stops[] = [$is_initial_color ? 0 : 100, '%', [$argument[1], $argument[2], $argument[3]]];
                    break;
                case 'list':
                    $list_data = $argument[2];
                    if ($list_data[0][0] == 'color' || $list_data[0][0] == 'raw_color') { //Color and position
                        $color_index = 0;
                        $position_index = 1;
                        $list_data[$color_index] = $this->coerceColor($list_data[$color_index]) ?? throw new InvalidArgumentException('Invalid gradient color');
                    } else { //Position and color
                        $color_index = 1;
                        $position_index = 0;
                    }
                    $color = [$list_data[$color_index][1], $list_data[$color_index][2], $list_data[$color_index][3]];
                    $position = $list_data[$position_index][1];
                    $unit = $list_data[$position_index][2];
                    $color_stops[] = [$position, $unit, $color];
                    break;
                case 'string': //Gradient type
                    $gradient_type = strtolower($this->_remove_quotes($argument[2][0]));
                    if ($gradient_type == 'vertical' && !$size_changed) {
                        $gradient_width = 1;
                        $gradient_height = 50;
                    } else {
                        if ($gradient_type == 'horizontal' && !$size_changed) {
                            $gradient_width = 50;
                            $gradient_height = 1;
                        }
                    }
                    break;
                case 'number': //Image size (first time received: width, other times: height)
                    if (!$size_changed) {
                        if ($gradient_type == 'vertical') //If the gradient is vertical, we only need the height parameter
                        {
                            $gradient_height = (int)$argument[1];
                        } else {
                            $gradient_width = (int)$argument[1];
                        }
                        $size_changed = true;
                    } else {
                        if ($gradient_type == 'vertical') //If the gradient is vertical and we have two parameters, restore width parameter
                        {
                            $gradient_width = $gradient_height;
                        }
                        $gradient_height = (int)$argument[1];
                    }
                    break;
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

        return ['string', '', ["$back_color;$css"]];
    }

    /**
     * Create an image sprite
     *
     * @param array<int, mixed> $arg lessphp value
     *
     * @return array<int, mixed>
     */
    public function lib_sprite(array $arg): array
    {
        //Get parameters
        $url = $this->_remove_quotes(trim($arg[2][0][2][0]));
        $group = $this->_remove_quotes(trim($arg[2][1][2][0]));

        //Get sprite
        if (!isset($this->_sprites[$group])) {
            $this->_sprites[$group] = new Tinyfier_Image_Sprite();
        }

        //Add image to sprite
        $file = $this->_local_path($url);
        $mark = 'CSSSPRITE_' . $group . '_' . md5($file);

        $this->_sprites[$group]->add_image($file, $mark);
        return ['string', '', [$mark]];
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

    private function _clear_path(string $path): string
    {
        // /cool/yeah/../zzz ==> /cool/zzz
        // bla/./bloo ==> bla/bloo
        // bla//bloo ==> bla/bloo
        return preg_replace(['/[^\/]+\/\.\.\//', '#(?<!\:)\/[\/\.]+#'], ['', '/'], $path)
               ?? throw new RuntimeException("Cannot clean path '{$path}'");
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
