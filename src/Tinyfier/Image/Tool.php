<?php

declare(strict_types=1);

/**
 * Basic image manipulation tool (resizing, format change, optimization, etc.)
 *
 * @phpstan-import-type OptimizerSettings from Tinyfier_Image_Optimizer
 */
class Tinyfier_Image_Tool
{

    protected GdImage $_handle;
    /**
     * Formato del fichero de origen ('' si la imagen se creó desde un handle)
     */
    protected string $_format = '';

    public function __construct(string|GdImage $path_or_handle)
    {
        $this->_handle = is_string($path_or_handle) ? $this->_load_image($path_or_handle) : $path_or_handle;
    }

    private function _load_image(string $path): GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new InvalidArgumentException("Invalid image path '{$path}'");
        }

        // GD ya no puede leer SWF, así que ese formato cae en imagecreatefromstring y falla
        [$this->_format, $handle] = match ($info[2]) {
            IMAGETYPE_GIF  => ['gif', imagecreatefromgif($path)],
            IMAGETYPE_JPEG => ['jpg', imagecreatefromjpeg($path)],
            IMAGETYPE_PNG  => ['png', imagecreatefrompng($path)],
            IMAGETYPE_WBMP => ['wbmp', imagecreatefromwbmp($path)],
            IMAGETYPE_XBM  => ['xbm', imagecreatefromxbm($path)],
            // GD puede estar compilado sin WebP o AVIF
            IMAGETYPE_WEBP => ['webp', function_exists('imagecreatefromwebp')
                ? imagecreatefromwebp($path)
                : throw new RuntimeException('GD was built without WebP support')],
            IMAGETYPE_AVIF => ['avif', function_exists('imagecreatefromavif')
                ? imagecreatefromavif($path)
                : throw new RuntimeException('GD was built without AVIF support')],
            default        => ['', imagecreatefromstring((string)file_get_contents($path))],
        };

        if ($handle === false) {
            throw new InvalidArgumentException("Invalid path or image handle '{$path}'");
        }

        return $handle;
    }

    /**
     * Gets the current image handle
     */
    public function handle(): GdImage
    {
        return $this->_handle;
    }

    /**
     * Gets the current image format
     */
    public function format(): string
    {
        return $this->_format;
    }

    /**
     * Return the current image width
     */
    public function width(): int
    {
        return imagesx($this->_handle);
    }

    /**
     * Return the current image height
     */
    public function height(): int
    {
        return imagesy($this->_handle);
    }

    /**
     * Resizes the current image
     *
     * @param bool $enlarge Disable image resizing when destination size is bigger than original size (only if keey_aspect=true)
     */
    public function resize(int $width, ?int $height = null, bool $keep_aspect = true, bool $enlarge = false): bool
    {
        $current_width = $this->width();
        $current_height = $this->height();

        if (!isset($height)) {
            $height = $keep_aspect ? PHP_INT_MAX : $current_height;
        }

        // Adjust final size
        if ($keep_aspect) {
            $aspect_ratio = min($width / $current_width, $height / $current_height);
            $dest_width = (int)round($current_width * $aspect_ratio);
            $dest_height = (int)round($current_height * $aspect_ratio);

            if (!$enlarge && ($dest_width > $current_width || $dest_height > $current_height)) {
                return true;
            }
        } else {
            $dest_width = $width;
            $dest_height = $height;
        }

        //Resize image
        $thumb = imagecreatetruecolor($dest_width, $dest_height);
        imagealphablending($thumb, false);
        $success = imagecopyresampled($thumb, $this->_handle, 0, 0, 0, 0, $dest_width, $dest_height, $current_width, $current_height);

        $this->_handle = $thumb;

        return $success;
    }

    /**
     * Sharpen the current image
     *
     * @param int $factor Sharpening factor (between 1 and 64)
     */
    public function sharpen(int $factor): void
    {
        if ($factor == 0) {
            return;
        }

        // get a value thats equal to 64 - abs( factor )
        // ( using min/max to limited the factor to 0 - 64 to not get out of range values )
        $val1Adjustment = 64 - min(64, max(0, abs($factor)));

        // the base factor for the "current" pixel depends on if we are blurring or sharpening.
        // If we are blurring use 1, if sharpening use 9.
        $val1Base = (abs($factor) != $factor)
            ? 1
            : 9;

        // value for the center/currrent pixel is:
        //  1 + 0 - max blurring
        //  1 + 64- minimal blurring
        //  9 + 64- minimal sharpening
        //  9 + 0 - maximum sharpening
        $val1 = $val1Base + $val1Adjustment;

        // the value for the surrounding pixels is either positive or negative depending on if we are blurring or sharpening.
        $val2 = (abs($factor) != $factor) ? 1 : -1;

        // get source values

        // setup matrix ..
        $matrix = [
            [$val2, $val2, $val2],
            [$val2, $val1, $val2],
            [$val2, $val2, $val2]
        ];

        // calculate the correct divisor
        // actual divisor is equal to "$divisor = $val1 + $val2 * 8;"
        // but the following line is more generic
        $divisor = array_sum(array_map(array_sum(...), $matrix));

        // apply the matrix
        imageconvolution($this->_handle, $matrix, $divisor, 0);
    }

    /**
     * Force the aspect ratio to the given value, stretching or flattening the image if necessary
     *
     * @param float    $ratio Proportion ratio between the width and the height
     * @param int|null $maxw  Maximum width allowed for the final image, null to disable
     * @param int|null $maxh  Maximum height allowed for the final image, null to disable
     * @param int|null $minw  Minimum width allowed for the final image, null to disable
     * @param int|null $minh  Minimum height allowed for the final image, null to disable
     */
    public function aspect_ratio(float $ratio, ?int $maxw = null, ?int $maxh = null, ?int $minw = null, ?int $minh = null): bool
    {
        $w = $this->width();
        $h = $this->height();

        //Set maximum
        if (isset($maxw)) {
            $w = min($maxw, $w);
            if (isset($maxh)) {
                $h = min($maxh, $h);
            } else {
                $h = min($maxw, $h);
            }
        }

        //Set minium
        if (isset($minw)) {
            $w = max($minw, $w);
            if (isset($minh)) {
                $h = max($minh, $h);
            } else {
                $h = max($minw, $h);
            }
        }

        //Resize to the final ratio
        if ($ratio > 1) { //Width>height
            $h = (int)round($w / $ratio);
        } else {
            $w = (int)round($h * $ratio);
        }

        return $this->resize($w, $h, false);
    }

    /**
     * Sets the background color for transparent images
     */
    public function set_bg_color(int $r = 255, int $g = 255, int $b = 255): void
    {
        // Create a new true color image with the same size
        $w = imagesx($this->_handle);
        $h = imagesy($this->_handle);
        $with_bg = imagecreatetruecolor($w, $h);

        // Fill the new image with white background
        $bg = imagecolorallocate($with_bg, $r, $g, $b);
        if ($bg === false) {
            throw new RuntimeException('Cannot allocate the background color');
        }
        imagefill($with_bg, 0, 0, $bg);

        // Copy original transparent image onto the new image
        imagecopy($with_bg, $this->_handle, 0, 0, 0, 0, $w, $h);

        $this->_handle = $with_bg;
    }

    /**
     * Apply a filter on the selected image. For the list of available filters
     * , please check http://php.net/manual/function.imagefilter.php
     */
    public function filter(string $filter, int|float|bool ...$args): bool
    {
        //Find filter constant
        if (is_numeric($filter)) {
            $filter_id = (int)$filter;
        } elseif (defined($filter)) {
            $filter_id = (int)constant($filter);
        } else {
            $replaces = [
                'desaturate' => 'grayscale',
                'invert'     => 'negate',
                'edges'      => 'edgedetect',
                'blur'       => 'gaussian_blur',
            ];

            foreach ($replaces as $s => $r) {
                if ($filter == $s) {
                    $filter = $r;
                    break;
                }
            }

            $filter = 'IMG_FILTER_' . strtoupper($filter);

            if (!defined($filter)) {
                throw new UnexpectedValueException("Filter '$filter' not valid");
            }

            $filter_id = (int)constant($filter);
        }

        //Apply filter
        return imagefilter($this->_handle, $filter_id, ...$args);
    }

    private function _is_transparent(int $in): bool
    {
        return imagecolorsforindex($this->_handle, $in)['alpha'] >= 127;
    }

    /**
     * Crop the current image, removing the transparent background area
     *
     * @param string|null $pad CSS-like padding: "top right bottom left", "top right bottom", "vertical horizontal" or "all"
     */
    public function crop(?string $pad = null): void
    {
        // Calculate padding for each side.
        if (isset($pad)) {
            $pp = explode(' ', $pad);
            if (isset($pp[3])) {
                $p = [(int)$pp[0], (int)$pp[1], (int)$pp[2], (int)$pp[3]];
            } else {
                if (isset($pp[2])) {
                    $p = [(int)$pp[0], (int)$pp[1], (int)$pp[2], (int)$pp[1]];
                } else {
                    if (isset($pp[1])) {
                        $p = [(int)$pp[0], (int)$pp[1], (int)$pp[0], (int)$pp[1]];
                    } else {
                        $p = array_fill(0, 4, (int)$pp[0]);
                    }
                }
            }
        } else {
            $p = array_fill(0, 4, 0);
        }

        // Get the image width and height.
        $imw = imagesx($this->_handle);
        $imh = imagesy($this->_handle);

        // Set the X variables.
        $xmin = $imw;
        $xmax = 0;
        $ymin = null;
        $ymax = 0;

        // Start scanning for the edges.
        for ($iy = 0; $iy < $imh; $iy++) {
            $first = true;
            for ($ix = 0; $ix < $imw; $ix++) {
                $ndx = imagecolorat($this->_handle, $ix, $iy);
                if (!$this->_is_transparent($ndx)) {
                    if ($xmin > $ix) {
                        $xmin = $ix;
                    }
                    if ($xmax < $ix) {
                        $xmax = $ix;
                    }
                    $ymin ??= $iy;
                    $ymax = $iy;
                    if ($first) {
                        $ix = $xmax;
                        $first = false;
                    }
                }
            }
        }

        // Imagen totalmente transparente: no hay nada que recortar
        if ($ymin === null) {
            return;
        }

        // The new width and height of the image. (not including padding)
        $imw = 1 + $xmax - $xmin; // Image width in pixels
        $imh = 1 + $ymax - $ymin; // Image height in pixels

        // Make another image to place the trimmed version in.
        $im2 = imagecreatetruecolor($imw + $p[1] + $p[3], $imh + $p[0] + $p[2]);

        // Make the background of the new image the same as the background of the old one.
        $transparent = imagecolorallocatealpha($im2, 255, 255, 255, 127);
        if ($transparent === false) {
            throw new RuntimeException('Cannot allocate the transparent color');
        }
        imagefill($im2, 0, 0, $transparent);

        // Copy it over to the new image.
        imagecopy($im2, $this->_handle, $p[3], $p[0], $xmin, $ymin, $imw, $imh);

        // To finish up, we replace the old image which is referenced.
        $this->_handle = $im2;
    }

    /**
     * Save the current image on the specified path
     *
     * @param string|null               $path           Path to the saved file, NULL to send it to the browser
     * @param bool|int                  $quality        Lossy quality of the saved file. TRUE for maximum quality and LOSSLESS optimization
     * @param bool|OptimizerSettings     $optimize       Optimize image after save. It can be an array of Tinyfier_Image_Optimizer::process settings
     * @param bool                      $check_is_equal Check, before save, that the file exists and it's equal to the current image (so it doesnt have to optimize again)
     * @param string|int|null           $format         Output format for the image (extension or IMAGETYPE_* constant), NULL to detect it from the file name
     *
     * @warning Optimization is not available when the image is sent to the browser
     */
    public function save(?string $path, bool|int $quality = 85, bool|array $optimize = true, bool $check_is_equal = false, string|int|null $format = null): bool
    {
        //Check if image exists and is equal
        if ($check_is_equal && $path !== null && file_exists($path) && self::equal($this, $path)) {
            return false; //Same images, don't overwrite
        }
        $format ??= str_replace('.', '', strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)));

        //Save image
        switch ($format) {
            case 'jpg':
            case 'jpeg':
            case IMAGETYPE_JPEG:
                $this->set_bg_color();
                $success = imagejpeg($this->_handle, $path, $optimize || is_bool($quality) ? 100 : $quality);
                break;

            case 'gif':
            case IMAGETYPE_GIF:
                $success = imagegif($this->_handle, $path);
                break;

            case 'png':
            case IMAGETYPE_PNG:
                imagesavealpha($this->_handle, true);
                $success = imagepng($this->_handle, $path, 9, PNG_ALL_FILTERS);
                $format = 'png';
                break;

            case 'webp':
            case IMAGETYPE_WEBP:
                if (!function_exists('imagewebp')) {
                    throw new RuntimeException('GD was built without WebP support');
                }
                // Mantener la transparencia. Sin optimizador externo, la calidad se aplica aquí (true = sin pérdida)
                imagealphablending($this->_handle, false);
                imagesavealpha($this->_handle, true);
                $success = imagewebp($this->_handle, $path, match (true) {
                    $quality === true  => IMG_WEBP_LOSSLESS,
                    $quality === false => -1,
                    default            => $quality,
                });
                break;

            case 'avif':
            case IMAGETYPE_AVIF:
                if (!function_exists('imageavif')) {
                    throw new RuntimeException('GD was built without AVIF support');
                }
                imagealphablending($this->_handle, false);
                imagesavealpha($this->_handle, true);
                $success = imageavif($this->_handle, $path, match (true) {
                    $quality === true  => 100,
                    $quality === false => -1,
                    default            => $quality,
                });
                break;

            default:
                throw new InvalidArgumentException("Unrecognized format '$format'");
        }

        //Optimize
        if ($optimize && $path !== null && $success) {
            $optimizer = new Tinyfier_Image_Optimizer();
            $optimizer->mode = $quality === true ? Tinyfier_Image_Optimizer::MODE_LOSSLESS : Tinyfier_Image_Optimizer::MODE_LOSSY;
            $optimizer->lossy_quality = is_int($quality) ? $quality : 100;
            if (is_array($optimize)) {
                foreach ($optimize as $k => $v) {
                    $optimizer->$k = $v;
                }
            }
            $optimizer->optimize($path);
        }

        return $success;
    }

    /**
     * Get the current image with the specified format
     */
    public function get(?string $format = null, int $quality = 85): string
    {
        ob_start();
        $this->save(null, $quality, true, false, $format);
        return (string)ob_get_clean();
    }

    /**
     * Get the mime type for the given format
     */
    public function mimetype(string $format = 'png'): string
    {
        return "image/{$format}";
    }

    /**
     * Send the current image to the browser
     */
    public function send(string $format = 'png', int $quality = 90): bool
    {
        header('Content-type: ' . $this->mimetype());
        return $this->save(null, $quality, false, false, $format);
    }

    /**
     * Check if a path refers to a valid image
     */
    public static function is_valid_image(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        if (function_exists('exif_imagetype')) {
            return exif_imagetype($path) !== false;
        } elseif (function_exists('getimagesize')) {
            return getimagesize($path) !== false;
        } else {
            throw new RuntimeException('Could not find exif_imagetype or getimagesize functions');
        }
    }

    /**
     * Do a pixel by pixel comparation of two images
     * @warning this function can take several minutes on large files
     *
     * @param self|string|GdImage $image_a Path or handle to the first image
     * @param self|string|GdImage $image_b Path or handle to the second image
     */
    public static function equal(self|string|GdImage $image_a, self|string|GdImage $image_b): bool
    {
        if (!($image_a instanceof self)) {
            $image_a = new self($image_a);
        }
        if (!($image_b instanceof self)) {
            $image_b = new self($image_b);
        }

        //Compare size
        if ($image_a->width() != $image_b->width() || $image_a->height() != $image_b->height()) {
            return false;
        }

        //Compare pixel
        $ha = $image_a->handle();
        $hb = $image_b->handle();
        for ($x = 0; $x <= imagesx($ha) - 1; $x++) {
            for ($y = 0; $y <= imagesy($ha) - 1; $y++) {
                $color_a = imagecolorsforindex($ha, imagecolorat($ha, $x, $y));
                $color_b = imagecolorsforindex($hb, imagecolorat($hb, $x, $y));

                if ($color_a != $color_b) {
                    //If alfa value is zero, color doesn't matter
                    if ($color_a['alpha'] != 0 || $color_b['alpha'] != 0) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

}
