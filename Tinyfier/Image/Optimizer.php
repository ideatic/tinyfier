<?php

/**
 * Tools for image optimization and compression
 * 
 * @note Much of the code has been obtained from the great EWWW Image Optimizer
 * Wordpress Plugin by nosilver4u (http://wordpress.org/plugins/ewww-image-optimizer/)
 */
abstract class Tinyfier_Image_Optimizer {

    /**
     * Sets the image processing mode (default: LOSSY)
     */
    const MODE = 'mode';
    const MODE_LOSSLESS = 'lossless';
    const MODE_LOSSY = 'lossy';
    const MODE_SMUSHIT = 'smushit';

    /**
     * Compression level. The higher the level, better compression but more
     * CPU usage. (Default: LEVEL_NORMAL)
     */
    const LEVEL = 'level';
    const LEVEL_FAST = 0;
    const LEVEL_NORMAL = 1;
    const LEVEL_HIGH = 2;
    const LEVEL_XTREME = 3;

    /**
     * Sets the desired quality for lossy compression
     * (from 0 - lowest quality to 100 - highest)
     */
    const LOSSY_QUALITY = 'lossy_quality';

    /**
     * Use a low priority process for image conversion (default: TRUE)
     */
    const LOW_PRIORITY = 'low_priority';

    /**
     * Remove metadata (like EXIF metadata) from processed files (default: TRUE)
     */
    const REMOVE_METADATA = 'remove_metadata';

    /**
     * Check output of lossless encoders by doing a pixel-by-pixel comparison (default: FALSE)
     * @warning very slow method
     */
    const CHECK_OUTPUT = 'check_output';

    /**
     * Dump debug information (default: FALSE)     * 
     */
    const VERBOSE = 'verbose';

    /**
     * Optimize an imagen given its path
     * @return boolean
     */
    public static function process($file, array $settings = array()) {
        if (!is_writable($file)) {
            throw new InvalidArgumentException("File '$file' is not writable");
        }

        //Merge default settings
        $settings = $settings + array(
            self::MODE => self::MODE_LOSSY,
            self::LOSSY_QUALITY => 75,
            self::LOW_PRIORITY => TRUE,
            self::REMOVE_METADATA => TRUE,
            self::CHECK_OUTPUT => FALSE,
            self::LEVEL => self::LEVEL_NORMAL,
            self::VERBOSE => FALSE,
        );

        if ($settings[self::MODE] == self::MODE_SMUSHIT) {
            //Optimize using Yahoo Smush.it
            $compressed = $this->_compress_smushit($file);

            if ($compressed) {
                file_put_contents($file, $compressed);
            }
        } else {
            //Detect mime type
            $mime = self::_detect_mime($file);

            if (!$mime) {
                throw new RuntimeException("Mimetype cannot be found for '$file'. Please check that at least one of these are available: finfo_file(), getimagesize() or mime_content_type()");
            }

            //Use 'nice' to reduce process priority
            $commad_prefix = '';
            if ($settings[self::LOW_PRIORITY] && ($nice = self::_find_tool('nice', FALSE, FALSE))) {
                $commad_prefix = "$nice ";
            }

            //Find tools for the current mime type and settings
            switch ($mime) {
                case 'image/jpeg':
                    self::_optimize_jpg($file, $settings, $commad_prefix);

                    break;

                case 'image/png':
                    self::_optimize_png($file, $settings, $commad_prefix);
                    break;

                case 'image/gif':
                    $gifsicle = self::_find_tool('gifsicle');

                    self::_exec("{$commad_prefix}{$gifsicle} -b -O3 --careful :file", array(
                        ':file' => $file
                            ), $settings);
                    break;

                default:
                    throw new RuntimeException("Unknown mime type '$mime' for file '$file'");
            }
            clearstatcache();
        }
    }

    private function _compress_smushit($file) {
        if (!function_exists('curl_init')) {
            return FALSE;
        }


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_URL, 'http://www.smushit.com/ysmush.it/ws.php');
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'files' => file_get_contents($file),
        ));
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
        return FALSE;
    }

    private static function _detect_mime($path) {
        $type = FALSE;

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
            if (FALSE !== $type) {
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

    private static function _find_tool($name, $local_search = TRUE, $throw_not_found = TRUE) {
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
            return FALSE;
        }
    }

    private static function _exec($command, $args, $settings) {
        $escaped_args = array();

        foreach ($args as $k => $v) {
            $escaped_args[$k] = escapeshellarg($v);
        }

        $command = strtr($command, $escaped_args);

        if ($settings[self::VERBOSE]) {
            clearstatcache();
            reset($args);
            $before = filesize(isset($args[':in']) ? $args[':in'] : current($args));
            $start = microtime(TRUE);
            exec($command, $output, $status);
            $time = round(microtime(TRUE) - $start, 3);
            clearstatcache();
            $after = filesize(isset($args[':out']) ? $args[':out'] : current($args));
            echo "<h5>$before » $after ($time s) <small>$command</small> ($status)<h5>";
            if (!empty($output)) {
                echo '<pre>' . print_r($output, TRUE) . '</pre>';
            }
            return $status;
        } else {
            return exec($command, $output, $status);
        }
    }

    /**
     * @param $file
     * @param $settings
     * @param $commad_prefix
     * @throws Exception
     */
    private static function _optimize_jpg($file, $settings, $commad_prefix) {
        if ($settings[self::MODE] == self::MODE_LOSSY) {
            $handle = imagecreatefromjpeg($file);
            if (!imagejpeg($handle, $file, $settings[self::LOSSY_QUALITY])) {
                throw new Exception("The image '$file' cannot be lossy optimized");
            }
            imagedestroy($handle);
        }

        if ($settings[self::CHECK_OUTPUT]) {
            $original_image = new Tinyfier_Image_Tool($file);
        }

        $jpegtran = self::_find_tool('jpegtran');

        //Run jpegtran (progressive and non-progressive versions)
        $temp_files = array();
        $metadata_copy = $settings[self::REMOVE_METADATA] ? 'none' : 'all';
        foreach (array('', '-progressive') as $flag) {
            $temp_files[$flag] = tempnam(sys_get_temp_dir(), 'jpegtran');

            self::_exec("{$commad_prefix}{$jpegtran} -copy {$metadata_copy} -optimize {$flag} -outfile :out :in", array(
                ':in' => $file,
                ':out' => $temp_files[$flag],
                    ), $settings);
        }

        //Find the lowest size
        $lowest_size = $original_size = filesize($file);
        $lowest_path = $file;
        foreach ($temp_files as $converted) {
            $size = filesize($converted);
            if ($size !== FALSE && $size < $lowest_size) {
                if (isset($original_image) && !Tinyfier_Image_Tool::equal($original_image, $converted)) {
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
    }

    /**
     * @param $file
     * @param $settings
     * @param $commad_prefix
     * @throws Exception
     */
    private static function _optimize_png($file, $settings, $commad_prefix) {
        //pngquant lossy compresssion
        if ($settings[self::MODE] == self::MODE_LOSSY) {
            $pngquant = self::_find_tool('pngquant');
            $min_quality = 50;
            $max_quality = max($min_quality, $settings[self::LOSSY_QUALITY]);
            $levels = array(
                self::LEVEL_FAST => 10,
                self::LEVEL_NORMAL => 3,
                self::LEVEL_HIGH => 1,
                self::LEVEL_XTREME => 1
            );

            //compress using a temp file because pngquant dont allow direct writing of input file
            $i = 0;
            do {
                $tmp = sys_get_temp_dir() . '/pngquant_' . $i . '.png';
                $i++;
            } while (file_exists($tmp));
            copy($file, $tmp);
            self::_exec("{$commad_prefix}{$pngquant} --speed {$levels[$settings[self::LEVEL]]} --quality={$min_quality}-{$max_quality} --ext _pq.png :file", array(
                ':file' => $tmp
                    ), $settings);

            $out = preg_replace('/\.png$/', '_pq.png', $tmp);

            if (file_exists($out)) {
                rename($out, $file);
            }

            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }

        if ($settings[self::CHECK_OUTPUT]) {
            $original_image = new Tinyfier_Image_Tool($file);
        }

        //Optipng Lossless compression
        $optipng = self::_find_tool('optipng');

        $metadata_copy = $settings[self::REMOVE_METADATA] ? '-strip all' : '';
        $levels = array(
            self::LEVEL_FAST => 2,
            self::LEVEL_NORMAL => 3,
            self::LEVEL_HIGH => 4,
            self::LEVEL_XTREME => 6
        );
        self::_exec("{$commad_prefix}{$optipng} -o{$levels[$settings[self::LEVEL]]} -quiet {$metadata_copy} :file", array(
            ':file' => $file
                ), $settings);

        //Pngout Lossless compression
        $pngout = self::_find_tool('pngout');

        $levels = array(
            self::LEVEL_FAST => 3,
            self::LEVEL_NORMAL => 2,
            self::LEVEL_HIGH => 1,
            self::LEVEL_XTREME => 0
        );
        self::_exec("{$commad_prefix}{$pngout} -s{$levels[$settings[self::LEVEL]]} -q :file", array(
            ':file' => $file
                ), $settings);

        if (isset($original_image) && !Tinyfier_Image_Tool::equal($original_image, $file)) {
            throw new Exception('Lossless compression output was different to original');
        }
    }

}
