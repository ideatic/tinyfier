<?php

declare(strict_types=1);

/**
 * Compression and processing routines for Javascript code
 *
 * @phpstan-type JsSettings array{
 *     // true: devuelve el código sin cambios (más fácil de leer)
 *     pretty?: bool,
 *     // false: no usa los compresores locales
 *     local?: bool,
 *     // Compresores a probar, en orden: nombres buscados en el PATH o rutas absolutas (terser, esbuild, uglifyjs)
 *     local_compressors?: list<string>,
 *     // Segundos como máximo para cada compresor
 *     local_timeout?: int,
 * }
 * @phpstan-type JsFullSettings array{
 *     // true: devuelve el código sin cambios (más fácil de leer)
 *     pretty: bool,
 *     // false: no usa los compresores locales
 *     local: bool,
 *     // Compresores a probar, en orden: nombres buscados en el PATH o rutas absolutas (terser, esbuild, uglifyjs)
 *     local_compressors: list<string>,
 *     // Segundos como máximo para cada compresor
 *     local_timeout: int,
 * }
 */
abstract class Tinyfier_JS_Tool
{
    /**
     * Compress javascript code
     *
     * @param JsSettings        $settings
     * @param list<string>|null $errors   Real failures of the compressors
     * @param list<string>|null $warnings Messages written by the compressors to stderr
     */
    public static function process(string $source, array $settings = [], ?array &$errors = [], ?array &$warnings = null): string
    {
        $errors ??= [];

        if ($source === '') {
            return $source;
        }

        // Default settings
        $settings = $settings + self::default_settings();

        if ((bool)$settings['pretty'] || !(bool)$settings['local'] || !Tinyfier_Process::available()) {
            return $source;
        }

        // Probar cada compresor local en orden; el primero que funcione gana
        $compressors = is_array($settings['local_compressors']) ? $settings['local_compressors'] : [];
        foreach ($compressors as $compressor) {
            $compressed = self::_compress_local((string)$compressor, $source, (int)$settings['local_timeout'], $errors, $warnings);
            if ($compressed !== null) {
                return $compressed;
            }
        }

        // Sin compresor disponible, se devuelve el código original sin cambios
        return $source;
    }

    /**
     * @return JsFullSettings
     */
    public static function default_settings(): array
    {
        return [
            'pretty'            => false,
            'local'             => true,
            'local_compressors' => ['terser', 'esbuild', 'uglifyjs'],
            'local_timeout'     => 30,
        ];
    }

    /**
     * Comprime el código con un compresor local. Devuelve null si no está instalado o si falla.
     *
     * @param list<string>      $errors
     * @param list<string>|null $warnings
     */
    private static function _compress_local(string $compressor, string $source, int $timeout, array &$errors, ?array &$warnings): ?string
    {
        // El tipo de compresor se deduce del nombre del ejecutable, así también vale una ruta absoluta
        $type = pathinfo($compressor, PATHINFO_FILENAME);
        $args = match ($type) {
            'terser', 'uglifyjs' => ['--compress', '--mangle'],
            'esbuild'            => ['--minify', '--loader=js', '--log-level=warning'],
            default              => null,
        };
        if ($args === null) {
            $errors[] = "Unknown JS compressor '{$compressor}'";
            return null;
        }

        // Un compresor que no está instalado no es un error: se prueba el siguiente
        $binary = Tinyfier_Process::find($compressor);
        if ($binary === false) {
            return null;
        }

        try {
            [$exit_code, $stdout, $stderr] = Tinyfier_Process::run([$binary, ...$args], $source, $timeout);
        } catch (RuntimeException $e) {
            $errors[] = "{$type}: {$e->getMessage()}";
            return null;
        }

        if ($exit_code !== 0) {
            $errors[] = "{$type} failed with exit code {$exit_code}: " . trim($stderr);
            return null;
        }
        if (trim($stdout) === '') {
            $errors[] = "{$type} returned an empty output";
            return null;
        }

        if ($warnings !== null && trim($stderr) !== '') {
            $warnings[] = "{$type}: " . trim($stderr);
        }

        return $stdout;
    }
}
