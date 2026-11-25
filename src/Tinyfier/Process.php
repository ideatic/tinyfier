<?php

declare(strict_types=1);

/**
 * Busca y ejecuta herramientas externas instaladas en el PATH (compresores JS, optimizadores de imágenes).
 * Nunca usa la shell: los comandos se pasan como array a proc_open.
 */
abstract class Tinyfier_Process
{
    /**
     * Rutas ya resueltas de cada herramienta (false si no se encontró), válidas durante la vida del proceso
     * @var array<string, string|false>
     */
    private static array $_binaries = [];

    /**
     * Indica si se pueden ejecutar procesos externos (proc_open puede estar desactivada en disable_functions)
     */
    public static function available(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * Busca un ejecutable en el PATH (o comprueba una ruta absoluta) sin usar la shell
     */
    public static function find(string $name): string|false
    {
        if (isset(self::$_binaries[$name])) {
            return self::$_binaries[$name];
        }

        $candidates = str_contains($name, '/') || str_contains($name, DIRECTORY_SEPARATOR)
            ? [$name]
            : array_map(
                static fn(string $dir): string => rtrim($dir, '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name,
                array_filter(explode(PATH_SEPARATOR, (string)getenv('PATH')), static fn(string $dir): bool => $dir !== '')
            );

        $found = array_find($candidates, static fn(string $path): bool => is_file($path) && is_executable($path));

        return self::$_binaries[$name] = $found ?? false;
    }

    /**
     * Ejecuta un comando (sin shell) pasándole $input por stdin, con un tiempo máximo
     *
     * @param list<string> $command
     *
     * @return array{int, string, string} Exit code, stdout and stderr
     */
    public static function run(array $command, string $input = '', int $timeout = 60): array
    {
        // stdout y stderr van a ficheros temporales, así el proceso nunca se bloquea por llenar una tubería
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Cannot create temporary files');
        }

        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes);
        if ($process === false) {
            throw new RuntimeException('Cannot start the process');
        }

        $deadline = microtime(true) + max(1, $timeout);

        // Escribir stdin sin bloquear, para poder cortar si el proceso se cuelga o deja de leer
        stream_set_blocking($pipes[0], false);
        $offset = 0;
        $length = strlen($input);
        while ($offset < $length && microtime(true) < $deadline) {
            // Si el proceso ya cerró su stdin, fwrite falla con "broken pipe": se deja de escribir
            $written = @fwrite($pipes[0], substr($input, $offset, 65536));
            if ($written === false) {
                break;
            }
            if ($written === 0) {
                // El exit code solo es válido la primera vez que se lee como terminado: se guarda $status
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(1000);
            }
            $offset += $written;
        }
        fclose($pipes[0]);

        // Esperar a que termine
        if (!isset($status) || $status['running']) {
            $status = proc_get_status($process);
        }
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(5000);
            $status = proc_get_status($process);
        }

        if ($status['running']) {
            proc_terminate($process, 9);
            proc_close($process);
            throw new RuntimeException("timed out after {$timeout} s");
        }
        proc_close($process);

        rewind($stdout);
        rewind($stderr);
        $result = [$status['exitcode'], (string)stream_get_contents($stdout), (string)stream_get_contents($stderr)];
        fclose($stdout);
        fclose($stderr);

        return $result;
    }
}
