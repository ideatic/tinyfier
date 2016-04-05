<?php

/**
 * Tools for image optimization and compression
 *
 * @note Much of the code has been obtained from the great EWWW Image Optimizer
 * Wordpress Plugin by nosilver4u (http://wordpress.org/plugins/ewww-image-optimizer/)
 */
class Tinyfier_Image_Optimizer
{

    /**
     * Sets the image processing mode (default: MODE_LOSSY)
     */
    const MODE_LOSSLESS = 'lossless';
    const MODE_LOSSY = 'lossy';
    const MODE_SMUSHIT = 'smushit';

    /**
     * Image processing mode
     * @var string
     */
    public $mode = self::MODE_LOSSY;

    const LEVEL_FAST = 0;
    const LEVEL_NORMAL = 1;
    const LEVEL_HIGH = 2;
    const LEVEL_EXTREME = 3;

    /**
     * Compression level. The higher the level, better compression but more
     * CPU usage. (Default: LEVEL_NORMAL)
     * @var int
     */
    public $level = self::LEVEL_NORMAL;

    /**
     * Sets the desired quality for lossy compression
     * (from 0 - lowest quality to 100 - highest, default 75)
     * @var int
     */
    public $lossy_quality = 75;

    /**
     * Use a low priority process for image conversion (default: TRUE)
     * @var bool
     */
    public $low_priority = true;


    /**
     * Remove metadata (like EXIF metadata) from processed files (default: TRUE)
     * @var bool
     */
    public $remove_metadata = true;

    /**
     * Check if output images are valid images (default: TRUE)
     * @var bool
     */
    public $check_output = true;

    /**
     * Check output of lossless encoders by doing a pixel-by-pixel comparison (default: FALSE)
     * @warning very slow method
     * @var bool
     */
    public $check_lossless_output = false;


    /**
     * Echo debug information (default: FALSE)
     * @var bool
     */
    public $verbose = false;

    /**
     * Optimize an imagen given its path
     *
     * @param string $file Image path
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     * @return bool
     */
    public function optimize($file)
    {
        if (!is_writable($file)) {
            throw new InvalidArgumentException("File '$file' is not writable");
        }

        if ($this->mode == self::MODE_SMUSHIT) {
            //Optimize using Yahoo Smush.it
            $compressed = $this->_compress_smushit($file);

            $success = !!$compressed;
            if ($success) {
                file_put_contents($file, $compressed);
            }
        } else {
            //Detect mime type
            $mime = $this->_detect_mime($file);

            if (!$mime) {
                throw new RuntimeException(
                    "Mimetype cannot be found for '$file'. Please check that at least one of these are available: finfo_file(), getimagesize() or mime_content_type()"
                );
            }

            //Use 'nice' to reduce process priority
            $commad_prefix = '';
            if ($this->low_priority && ($nice = self::_find_tool('nice', false, false))) {
                $commad_prefix = "$nice ";
            }

            //Find tools for the current mime type and settings
            switch ($mime) {
                case 'image/jpeg':
                    $success = $this->_optimize_jpg($file, $commad_prefix);

                    break;

                case 'image/png':
                    $success = $this->_optimize_png($file, $commad_prefix);
                    break;

                case 'image/gif':
                    $gifsicle = self::_find_tool('gifsicle');

                    $success = $this->_exec(
                        "{$commad_prefix}{$gifsicle} -b -O3 --careful :file",
                        array(
                            ':file' => $file
                        )
                    );
                    break;

                default:
                    throw new RuntimeException("Unknown mime type '$mime' for file '$file'");
            }
            clearstatcache();
        }

        return $success;
    }

    /**
     * Optimize an imagen given its path
     * @return boolean
     */
    public static function process($file, array $settings = array())
    {
        $optimizer = new self();
        foreach ($settings as $k => $v) {
            $optimizer->$k = $v;
        }
        return $optimizer->optimize($file);
    }

    private function _compress_smushit($file)
    {
        if (!function_exists('curl_init')) {
            return false;
        }


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_URL, 'http://www.smushit.com/ysmush.it/ws.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            array(
                'files' => file_get_contents($file),
            )
        );
        $json_str = curl_exec($ch);

        //Parse response and save file
        $json = json_decode($json_str);

        if ($json && !isset($json->error)) {
            curl_close($ch);
            return file_get_contents($json->dest);
        } else {
            curl_close($ch);
            throw new Exception("smushit error: $json_str");
        }

        curl_close($ch);
        return false;
    }

    private function _detect_mime($path)
    {
        $type = false;

        //1st method: using finfo
        if (function_exists('finfo_file') && defined('FILEINFO_MIME')) {
            // create a finfo resource
            $finfo = finfo_open(FILEINFO_MIME);
            // retrieve the mimetype
            $type = explode(';', finfo_file($finfo, $path));
            $type = $type[0];
            finfo_close($finfo);
        }

        //2nd method: getimagesize
        if (empty($type) && function_exists('getimagesize')) {
            $type = getimagesize($path);
            // make sure we have results
            if (false !== $type) {
                // store the mime-type
                $type = $type['mime'];
            }
        }

        //3rd method: the deprecated mime_content_type()
        if (empty($type) && function_exists('mime_content_type')) {
            $type = mime_content_type($path);
        }

        return $type;
    }


    private function _exec($command, $args)
    {
        $escaped_args = array();

        foreach ($args as $k => $v) {
            $escaped_args[$k] = escapeshellarg($v);
        }

        $command = strtr($command, $escaped_args);

        if ($this->verbose) {
            clearstatcache();
            reset($args);
            $before = self::_format_size(filesize(isset($args[':in']) ? $args[':in'] : current($args)));
            $start = microtime(true);
            exec($command, $output, $status);
            $time = round(microtime(true) - $start, 3);
            clearstatcache();
            $after = self::_format_size(filesize(isset($args[':out']) ? $args[':out'] : current($args)));
            echo "<h5>$before » $after ($time s) <small>$command</small> (returned $status)</h5>";
            if (!empty($output)) {
                echo '<pre>' . print_r($output, true) . '</pre>';
            }
            return $status;
        } else {
            return exec($command, $output, $status);
        }
    }

    /**
     * @param $file
     * @param $commad_prefix
     *
     * @throws Exception
     */
    private function _optimize_jpg($file, $commad_prefix)
    {
        if ($this->mode == self::MODE_LOSSY) {
            $handle = imagecreatefromjpeg($file);
            if (!imagejpeg($handle, $file, $this->lossy_quality)) {
                throw new Exception("The image '$file' cannot be lossy optimized");
            }
            imagedestroy($handle);
        } elseif ($this->check_lossless_output) {
            $original_image = new Tinyfier_Image_Tool($file);
        }

        $jpegtran = self::_find_tool('jpegtran');

        //Run jpegtran (progressive and non-progressive versions)
        $temp_files = array();
        $metadata_copy = $this->remove_metadata ? 'none' : 'all';
        foreach (array('', '-progressive') as $flag) {
            $temp_files[$flag] = tempnam(sys_get_temp_dir(), 'jpegtran');

            $this->_exec(
                "{$commad_prefix}{$jpegtran} -copy {$metadata_copy} -optimize {$flag} -outfile :out :in",
                array(
                    ':in'  => $file,
                    ':out' => $temp_files[$flag],
                )
            );
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
     * @param $file
     * @param $commad_prefix
     *
     * @throws Exception
     */
    private function _optimize_png($file, $commad_prefix)
    {
        //pngquant lossy compresssion
        if ($this->mode == self::MODE_LOSSY) {
            $pngquant = self::_find_tool('pngquant');
            $min_quality = 50;
            $max_quality = max($min_quality, $this->lossy_quality);
            $levels = array(
                self::LEVEL_FAST    => 10,
                self::LEVEL_NORMAL  => 3,
                self::LEVEL_HIGH    => 1,
                self::LEVEL_EXTREME => 1
            );

            //compress using a temp file because pngquant dont allow direct writing of input file
            $i = 0;
            do {
                $tmp = sys_get_temp_dir() . '/pngquant_' . $i . '.png';
                $i++;
            } while (file_exists($tmp));
            copy($file, $tmp);
            $this->_exec(
                "{$commad_prefix}{$pngquant} --speed {$levels[$this->level]} --quality={$min_quality}-{$max_quality} --ext _pq.png :file",
                array(
                    ':file' => $tmp
                )
            );

            $out = preg_replace('/\.png$/', '_pq.png', $tmp);

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
        $optipng = self::_find_tool('optipng');

        $metadata_copy = $this->remove_metadata ? '-strip all' : '';
        $levels = array(
            self::LEVEL_FAST    => 2,
            self::LEVEL_NORMAL  => 3,
            self::LEVEL_HIGH    => 4,
            self::LEVEL_EXTREME => 6
        );
        $this->_exec(
            "{$commad_prefix}{$optipng} -o{$levels[$this->level]} -quiet {$metadata_copy} :file",
            array(
                ':file' => $file
            )
        );

        //Pngout Lossless compression
        $pngout = self::_find_tool('pngout');

        $levels = array(
            self::LEVEL_FAST    => 3,
            self::LEVEL_NORMAL  => 2,
            self::LEVEL_HIGH    => 1,
            self::LEVEL_EXTREME => 0
        );
        $this->_exec(
            "{$commad_prefix}{$pngout} -s{$levels[$this->level]} -q :file",
            array(
                ':file' => $file
            )
        );

        if (isset($original_image) && !Tinyfier_Image_Tool::equal($original_image, $file)) {
            throw new Exception('Lossless compression output was different to original');
        }
        return true;
    }


    private static function _find_tool($name, $local_search = true, $throw_not_found = true)
    {
        //Search in local tools
        $paths = array();
        if ($local_search) {
            $base_path = dirname(__FILE__) . '/tools/' . $name;

            $ext = '';
            switch (PHP_OS) {
                case 'Linux':
                    $os = '-linux';
                    break;
                case 'WINNT':
                case 'WIN32':
                case 'Windows':
                    $os = '';
                    $ext = '.exe';
                    break;
                case 'Darwin':
                    $os = '-mac';
                    break;
                case 'SunOS':
                    $os = '-sol';
                    break;
                case 'FreeBSD':
                    $os = '-fbsd';
                    break;
            }

            if (function_exists('php_uname')) {
                $arch = php_uname('m');
                if ($arch) {
                    $paths[] = "{$base_path}/{$name}{$os}-{$arch}{$ext}";
                }
            }
            $paths[] = "{$base_path}/{$name}{$os}{$ext}";
        }


        //Search in system paths
        if (PHP_OS != 'WINNT') {
            $paths[] = '/usr/bin/' . $name;
            $paths[] = '/usr/local/bin/' . $name;
            $paths[] = '/usr/gnu/bin/' . $name;
        }

        //Find the correct path
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        if ($throw_not_found) {
            throw new RuntimeException("$name not found or with no permission, optimization cannot be done");
        } else {
            return false;
        }
    }


    private static function _format_size($size, $kilobyte = 1024, $format = '%size% %unit%')
    {

        $size = $size / $kilobyte; // Convertir bytes a kilobyes
        $units = array('KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        foreach ($units as $unit) {
            if ($size > $kilobyte) {
                $size = $size / $kilobyte;
            } else {
                break;
            }
        }

        return strtr(
            $format,
            array(
                '%size%' => round($size, 2),
                '%unit%' => $unit
            )
        );
    }
}
