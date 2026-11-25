<?php

declare(strict_types=1);

/**
 * Tools for image optimization and compression
 *
 * @note Much of the code has been obtained from the great EWWW Image Optimizer
 * Wordpress Plugin by nosilver4u (http://wordpress.org/plugins/ewww-image-optimizer/)
 *
 * @phpstan-type OptimizerSettings array{
 *     // Uno de MODE_*
 *     mode?: string,
 *     // Uno de LEVEL_*
 *     level?: int,
 *     // Calidad para compresión con pérdida (0-100)
 *     lossy_quality?: int,
 *     // Ejecutar las herramientas con 'nice'
 *     low_priority?: bool,
 *     // Quitar metadatos (EXIF, ...)
 *     remove_metadata?: bool,
 *     // Comprobar que las imágenes generadas son válidas
 *     check_output?: bool,
 *     // Comparar pixel a pixel la salida sin pérdida (lento)
 *     check_lossless_output?: bool,
 *     // Mostrar información de depuración
 *     verbose?: bool,
 *     // Segundos como máximo para cada herramienta externa
 *     timeout?: int,
 * }
 */
class Tinyfier_Image_Optimizer
{

    /**
     * Sets the image processing mode (default: MODE_LOSSY)
     */
    public const string MODE_LOSSLESS = 'lossless';
    public const string MODE_LOSSY = 'lossy';

    /**
     * Image processing mode
     * @var string
     */
    public string $mode = self::MODE_LOSSY;

    public const int LEVEL_FAST = 0;
    public const int LEVEL_NORMAL = 1;
    public const int LEVEL_HIGH = 2;
    public const int LEVEL_EXTREME = 3;

    /**
     * Compression level. The higher the level, better compression but more
     * CPU usage. (Default: LEVEL_NORMAL)
     * @var int
     */
    public int $level = self::LEVEL_NORMAL;

    /**
     * Sets the desired quality for lossy compression
     * (from 0 - lowest quality to 100 - highest, default 75)
     * @var int
     */
    public int $lossy_quality = 75;

    /**
     * Use a low priority process for image conversion (default: TRUE)
     * @var bool
     */
    public bool $low_priority = true;


    /**
     * Remove metadata (like EXIF metadata) from processed files (default: TRUE)
     * @var bool
     */
    public bool $remove_metadata = true;

    /**
     * Check if output images are valid images (default: TRUE)
     * @var bool
     */
    public bool $check_output = true;

    /**
     * Check output of lossless encoders by doing a pixel-by-pixel comparison (default: FALSE)
     * @warning very slow method
     * @var bool
     */
    public bool $check_lossless_output = false;


    /**
     * Echo debug information (default: FALSE)
     * @var bool
     */
    public bool $verbose = false;

    /**
     * Max seconds for each external tool (default: 60)
     */
    public int $timeout = 60;

    /**
     * Optimize an imagen given its path
     *
     * @param string $file Image path
     *
     * @return bool
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function optimize(string $file): bool
    {
        if (!is_writable($file)) {
            throw new InvalidArgumentException("File '$file' is not writable");
        }

        //Detect mime type
        $mime = $this->_detect_mime($file);

        if (!$mime) {
            throw new RuntimeException(
                "Mimetype cannot be found for '$file'. Please check that at least one of these are available: finfo_file(), getimagesize() or mime_content_type()"
            );
        }

        // Las herramientas solo se usan si están instaladas en el PATH; si falta alguna, ese paso se omite
        //Find tools for the current mime type and settings
        switch ($mime) {
            case 'image/jpeg':
                $success = $this->_optimize_jpg($file);

                break;

            case 'image/png':
                $success = $this->_optimize_png($file);
                break;

            case 'image/gif':
                $gifsicle = Tinyfier_Process::find('gifsicle');
                $success = $gifsicle !== false && $this->_exec([$gifsicle, '-b', '-O3', '--careful', $file], $file);
                break;

            case 'image/webp':
            case 'image/avif':
                // No se usan herramientas externas: GD ya aplicó la calidad al guardar
                $success = false;
                break;

            default:
                throw new RuntimeException("Unknown mime type '$mime' for file '$file'");
        }
        clearstatcache();

        return $success;
    }

    /**
     * Optimize an imagen given its path
     *
     * @param OptimizerSettings $settings Values for the public properties of this class
     */
    public static function process(string $file, array $settings = []): bool
    {
        $optimizer = new self();
        foreach ($settings as $k => $v) {
            $optimizer->$k = $v;
        }
        return $optimizer->optimize($file);
    }

    private function _detect_mime(string $path): string
    {
        $type = '';

        //1st method: using finfo
        if (function_exists('finfo_file') && defined('FILEINFO_MIME')) {
            $finfo = finfo_open(FILEINFO_MIME);
            $mime = $finfo !== false ? finfo_file($finfo, $path) : false;
            if ($mime !== false) {
                // retrieve the mimetype
                $type = explode(';', $mime)[0];
            }
        }

        //2nd method: getimagesize
        if ($type === '' && function_exists('getimagesize')) {
            $type = getimagesize($path)['mime'] ?? '';
        }

        //3rd method: the deprecated mime_content_type()
        if ($type === '' && function_exists('mime_content_type')) {
            $type = (string)mime_content_type($path);
        }

        return $type;
    }


    /**
     * Ejecuta una herramienta (sin shell, con tiempo máximo). Devuelve true si termina con exit code 0
     *
     * @param list<string> $command Binary and arguments
     * @param string       $in      Input file (only for the verbose output)
     * @param string|null  $out     Output file, if it is not $in (only for the verbose output)
     */
    private function _exec(array $command, string $in, ?string $out = null): bool
    {
        if (!Tinyfier_Process::available()) {
            return false;
        }

        // Usar 'nice' para bajar la prioridad del proceso
        if ($this->low_priority && ($nice = Tinyfier_Process::find('nice')) !== false) {
            array_unshift($command, $nice);
        }

        clearstatcache();
        $before = $this->verbose ? self::_format_size((int)filesize($in)) : '';
        $start = microtime(true);
        try {
            [$status, $stdout, $stderr] = Tinyfier_Process::run($command, '', $this->timeout);
        } catch (RuntimeException $e) {
            [$status, $stdout, $stderr] = [-1, '', $e->getMessage()];
        }

        if ($this->verbose) {
            $time = round(microtime(true) - $start, 3);
            clearstatcache();
            $after = self::_format_size((int)filesize($out ?? $in));
            $command_line = htmlspecialchars(implode(' ', $command));
            echo "<h5>$before » $after ($time s) <small>{$command_line}</small> (returned $status)</h5>";
            if (trim($stdout . $stderr) !== '') {
                echo '<pre>' . htmlspecialchars($stdout . $stderr) . '</pre>';
            }
        }
        return $status === 0;
    }

    /**
     * @throws RuntimeException
     */
    private function _optimize_jpg(string $file): bool
    {
        if ($this->mode == self::MODE_LOSSY) {
            $handle = imagecreatefromjpeg($file);
            if ($handle === false || !imagejpeg($handle, $file, $this->lossy_quality)) {
                throw new RuntimeException("The image '$file' cannot be lossy optimized");
            }
        } elseif ($this->check_lossless_output) {
            $original_image = new Tinyfier_Image_Tool($file);
        }

        $jpegtran = Tinyfier_Process::find('jpegtran');
        if ($jpegtran === false) {
            return true;
        }

        //Run jpegtran (progressive and non-progressive versions)
        $temp_files = [];
        $metadata_copy = $this->remove_metadata ? 'none' : 'all';
        foreach (['', '-progressive'] as $flag) {
            $temp_file = tempnam(sys_get_temp_dir(), 'jpegtran')
                         ?: throw new RuntimeException('Cannot create a temporary file for jpegtran');

            $success = $this->_exec(
                [$jpegtran, '-copy', $metadata_copy, '-optimize', ...($flag === '' ? [] : [$flag]), '-outfile', $temp_file, $file],
                $file,
                $temp_file
            );

            // Si jpegtran falla, el fichero temporal queda vacío o a medias: nunca debe sustituir a la imagen
            clearstatcache();
            if ($success && (int)filesize($temp_file) > 0) {
                $temp_files[$flag] = $temp_file;
            } else {
                unlink($temp_file);
            }
        }

        //Find the lowest size
        $lowest_size = $original_size = filesize($file);
        $lowest_path = $file;
        foreach ($temp_files as $converted) {
            $size = filesize($converted);
            if ($size !== false && $size < $lowest_size) {
                //Check if the image is valid
                if ($this->check_output && !Tinyfier_Image_Tool::is_valid_image($converted)) {
                    if ($this->verbose) {
                        echo "<h5>Ignored invalid image '$converted' generated by jpegtran</h5>";
                    }
                    continue;
                }
                //Check if the image is exactly equal
                if (isset($original_image) && !Tinyfier_Image_Tool::equal($original_image, $converted)) {
                    if ($this->verbose) {
                        echo "<h5>Ignored image '$converted' generated by jpegtran because was different from original</h5>";
                    }
                    continue;
                }

                $lowest_size = $size;
                $lowest_path = $converted;
            }
        }

        //Replace original and remove temp files
        if ($lowest_path != $file) {
            rename($lowest_path, $file);
        }
        foreach ($temp_files as $converted) {
            if (file_exists($converted)) {
                unlink($converted);
            }
        }

        return true;
    }

    /**
     * @throws RuntimeException
     */
    private function _optimize_png(string $file): bool
    {
        //pngquant lossy compresssion
        $pngquant = $this->mode == self::MODE_LOSSY ? Tinyfier_Process::find('pngquant') : false;
        if ($pngquant !== false) {
            $min_quality = 50;
            $max_quality = max($min_quality, $this->lossy_quality);
            $levels = [
                self::LEVEL_FAST    => 10,
                self::LEVEL_NORMAL  => 3,
                self::LEVEL_HIGH    => 1,
                self::LEVEL_EXTREME => 1
            ];

            //compress using a temp file because pngquant dont allow direct writing of input file
            $i = 0;
            do {
                $tmp = sys_get_temp_dir() . '/pngquant_' . $i . '.png';
                $i++;
            } while (file_exists($tmp));
            copy($file, $tmp);
            $out = substr($tmp, 0, -strlen('.png')) . '_pq.png';
            $this->_exec(
                [$pngquant, '--speed', (string)$levels[$this->level], "--quality={$min_quality}-{$max_quality}", '--ext', '_pq.png', $tmp],
                $tmp,
                $out
            );

            if (file_exists($out)) {
                if (!$this->check_output || Tinyfier_Image_Tool::is_valid_image($out)) {
                    rename($out, $file);
                } else {
                    if ($this->verbose) {
                        echo "<h5>Ignored invalid image '$out' generated by pngquant</h5>";
                    }
                    unlink($out);
                }
            }

            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }

        if ($this->check_lossless_output) {
            $original_image = new Tinyfier_Image_Tool($file);
        }

        //Optipng Lossless compression
        $optipng = Tinyfier_Process::find('optipng');
        if ($optipng !== false) {
            $levels = [
                self::LEVEL_FAST    => 2,
                self::LEVEL_NORMAL  => 3,
                self::LEVEL_HIGH    => 4,
                self::LEVEL_EXTREME => 6
            ];
            $this->_exec(
                [$optipng, "-o{$levels[$this->level]}", '-quiet', ...($this->remove_metadata ? ['-strip', 'all'] : []), $file],
                $file
            );
        }

        //Pngout Lossless compression
        $pngout = Tinyfier_Process::find('pngout');
        if ($pngout !== false) {
            $levels = [
                self::LEVEL_FAST    => 3,
                self::LEVEL_NORMAL  => 2,
                self::LEVEL_HIGH    => 1,
                self::LEVEL_EXTREME => 0
            ];
            $this->_exec([$pngout, "-s{$levels[$this->level]}", '-q', $file], $file);
        }

        if (isset($original_image) && !Tinyfier_Image_Tool::equal($original_image, $file)) {
            throw new RuntimeException('Lossless compression output was different to original');
        }
        return true;
    }


    private static function _format_size(int|float $size, int $kilobyte = 1024, string $format = '%size% %unit%'): string
    {
        $size = $size / $kilobyte; // Convertir bytes a kilobyes
        $units = ['KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $unit = array_first($units);
        foreach ($units as $unit) {
            if ($size > $kilobyte) {
                $size = $size / $kilobyte;
            } else {
                break;
            }
        }

        return strtr(
            $format,
            [
                '%size%' => (string)round($size, 2),
                '%unit%' => $unit
            ]
        );
    }
}
