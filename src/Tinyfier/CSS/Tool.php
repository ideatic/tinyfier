<?php

declare(strict_types=1);

/**
 * Compression and processing routines for CSS code
 *
 * @phpstan-import-type OptimizerSettings from Tinyfier_Image_Optimizer
 * @phpstan-type CssSettings array{
 *     // Procesar el código con lessphp
 *     less?: bool,
 *     // Ruta absoluta al fichero ('' si el CSS no viene de un fichero)
 *     absolute_path?: string,
 *     // URL del fichero, para reescribir las url() relativas
 *     url_path?: string,
 *     // Carpeta donde se guardan las imágenes generadas (degradados, sprites, filtros)
 *     cache_path?: string,
 *     // URL pública de cache_path
 *     cache_url?: string,
 *     // true: quita espacios y caracteres sobrantes; false: salida legible
 *     compress?: bool,
 *     // Optimizar colores, unidades, etc.
 *     optimize?: bool,
 *     // Unir selectores (puede no ser seguro)
 *     extra_optimize?: bool,
 *     // Optimizar las imágenes generadas; un array son ajustes de Tinyfier_Image_Optimizer
 *     optimize_images?: bool|OptimizerSettings,
 *     // Calidad de las imágenes con pérdida; true = sin pérdida
 *     lossy_quality?: bool|int,
 *     // Variables para el código LESS
 *     data?: array<string, mixed>|null,
 *     // Prefijos de navegador que añade css_optimizer
 *     prefixes?: string,
 * }
 * @phpstan-type CssFullSettings array{
 *     // Procesar el código con lessphp
 *     less: bool,
 *     // Ruta absoluta al fichero ('' si el CSS no viene de un fichero)
 *     absolute_path: string,
 *     // URL del fichero, para reescribir las url() relativas
 *     url_path: string,
 *     // Carpeta donde se guardan las imágenes generadas (degradados, sprites, filtros)
 *     cache_path: string,
 *     // URL pública de cache_path
 *     cache_url: string,
 *     // true: quita espacios y caracteres sobrantes; false: salida legible
 *     compress: bool,
 *     // Optimizar colores, unidades, etc.
 *     optimize: bool,
 *     // Unir selectores (puede no ser seguro)
 *     extra_optimize: bool,
 *     // Optimizar las imágenes generadas; un array son ajustes de Tinyfier_Image_Optimizer
 *     optimize_images: bool|OptimizerSettings,
 *     // Calidad de las imágenes con pérdida; true = sin pérdida
 *     lossy_quality: bool|int,
 *     // Variables para el código LESS
 *     data: array<string, mixed>|null,
 *     // Prefijos de navegador que añade css_optimizer
 *     prefixes: string,
 * }
 */
abstract class Tinyfier_CSS_Tool
{

    /**
     * Process a CSS file
     *
     * @param CssSettings $settings
     */
    public static function process_file(string $file, array $settings = []): string
    {
        $css = file_get_contents($file);
        if ($css === false) {
            throw new RuntimeException("Cannot read CSS file '{$file}'");
        }

        $settings['absolute_path'] = $file;
        return self::process($css, $settings);
    }

    /**
     * Process CSS code
     *
     * @param CssSettings $settings
     */
    public static function process(string $css, array $settings = []): string
    {
        //Load settings
        $settings = $settings + self::default_settings();

        // 1. Process the file with LESS    
        if ((bool)$settings['less']) {
            $less = new Tinyfier_CSS_LESS();
            $css = $less->process($css, $settings);
        }

        // 2. Optimize, compress and add vendor prefixes
        $optimizer = new css_optimizer(
            [
                'compress'        => (bool)$settings['compress'],
                'optimize'        => (bool)$settings['optimize'],
                'extra_optimize'  => (bool)$settings['extra_optimize'],
                'remove_ie_hacks' => false,
                'prefixes'        => $settings['prefixes'],
            ]
        );
        $result = $optimizer->process($css);

        // Con una cadena de entrada, css_optimizer devuelve siempre una cadena
        if (!is_string($result)) {
            throw new UnexpectedValueException('css_optimizer did not return a string');
        }

        return $result;
    }

    /**
     * @return CssFullSettings
     */
    public static function default_settings(): array
    {
        return [
            'less'            => true,
            'absolute_path'   => '',
            'url_path'        => '',
            'cache_path'      => '',
            'cache_url'       => '/cache/',
            'compress'        => true,
            'optimize'        => true,
            'extra_optimize'  => false,
            'optimize_images' => true,
            'lossy_quality'   => 75,
            'data'            => null,
            'prefixes'        => 'all'
        ];
    }

}
