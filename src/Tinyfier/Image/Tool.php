<?php

/**
 * Basic image manipulation tool (resizing, format change, optimization, etc.)
 */
class Tinyfier_Image_Tool
{

    protected $_handle;
    protected $_format;

    public function __construct($path_or_handle)
    {
        if (is_string($path_or_handle)) {
            $this->_handle = $this->_load_image($path_or_handle);
        } else {
            $this->_handle = $path_or_handle;
        }

        if (!$this->_handle) {
            throw new InvalidArgumentException('Invalid path or image handle');
        }
    }

    private function _load_image($path)
    {
        list($w, $h, $type) = getimagesize($path);
        switch ($type) {
            case IMAGETYPE_GIF :
                $this->_format = 'gif';
                return imagecreatefromgif($path);

            case IMAGETYPE_JPEG:
                $this->_format = 'jpg';
                return imagecreatefromjpeg($path);

            case IMAGETYPE_PNG:
                $this->_format = 'png';
                return imagecreatefrompng($path);

            case IMAGETYPE_SWF :
                $this->_format = 'swf';
                return imagecreatefromswf($path);

            case IMAGETYPE_WBMP :
                $this->_format = 'wbmp';
                return imagecreatefromwbmp($path);

            case IMAGETYPE_XBM :
                $this->_format = 'xbm';
                return imagecreatefromxbm($path);

            default:
                return imagecreatefromstring(file_get_contents($path));
        }
        return false;
    }

    /**
     * Gets the current image handle
     * @return mixed
     */
    public function handle()
    {
        return $this->_handle;
    }

    /**
     * Gets the current image format
     */
    public function format()
    {
        return $this->_format;
    }

    /**
     * Return the current image width
     * @return int
     */
    public function width()
    {
        return imagesx($this->_handle);
    }

    /**
     * Return the current image height
     * @return int
     */
    public function height()
    {
        return imagesy($this->_handle);
    }

    /**
     * Resizes the current image
     *
     * @param int     $width
     * @param int     $height
     * @param boolean $keep_aspect
     * @param boolean $enlarge Disable image resizing when destination size is bigger than original size (only if keey_aspect=true)
     *
     * @return boolean
     */
    public function resize($width, $height = null, $keep_aspect = true, $enlarge = false)
    {
        $current_width = $this->width();
        $current_height = $this->height();

        if (!isset($height)) {
            $height = $keep_aspect ? PHP_INT_MAX : $current_height;
        }

        //Adjust final size
        if ($keep_aspect) {
            $aspect_ratio = min($width / $current_width, $height / $current_height);
            $dest_width = round($current_width * $aspect_ratio);
            $dest_height = round($current_height * $aspect_ratio);

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
        imagedestroy($this->_handle);

        $this->_handle = $thumb;

        return $success;
    }

    /**
     * Sharpen the current image
     *
     * @param int $factor Sharpening factor (between 1 and 64)
     */
    public function sharpen($factor)
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
        $divisor = array_sum(array_map('array_sum', $matrix));

        // apply the matrix
        imageconvolution($this->_handle, $matrix, $divisor, 0);
    }

    /**
     * Force the aspect ratio to the given value, stretching or flattening the image if necessary
     *
     * @param float $ratio Proportion ratio between the width and the height
     * @param int   $maxw  Maximum width allowed for the final image, null to disable
     * @param int   $maxh  Maximum height allowed for the final image, null to disable
     * @param int   $minw  Minimum width allowed for the final image, null to disable
     * @param int   $minh  Minimum height allowed for the final image, null to disable
     *
     * @return boolean
     */
    public function aspect_ratio($ratio, $maxw = null, $maxh = null, $minw = null, $minh = null)
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
            $h = round($w / $ratio);
        } else {
            $w = round($h * $ratio);
        }

        return $this->resize($w, $h, false);
    }

    /**
     * Sets the background color for transparent images
     */
    public function set_bg_color($r = 255, $g = 255, $b = 255)
    {
        // Create a new true color image with the same size
        $w = imagesx($this->_handle);
        $h = imagesy($this->_handle);
        $with_bg = imagecreatetruecolor($w, $h);

        // Fill the new image with white background
        $bg = imagecolorallocate($with_bg, $r, $g, $b);
        imagefill($with_bg, 0, 0, $bg);

        // Copy original transparent image onto the new image
        imagecopy($with_bg, $this->_handle, 0, 0, 0, 0, $w, $h);

        $this->_handle = $with_bg;
    }

    /**
     * Apply a filter on the selected image. For the list of available filters
     * , please check http://php.net/manual/function.imagefilter.php
     *
     * @param string $path
     * @param string $filter
     */
    public function filter($filter, $arg1 = '', $arg2 = '', $arg3 = '')
    {
        //Find filter constant
        if (!is_numeric($filter) && !defined($filter)) {
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

            $filter = constant($filter);
        }

        //Apply filter
        $args = func_get_args();
        array_shift($args);
        array_unshift($args, $filter);
        array_unshift($args, $this->_handle);
        return call_user_func_array('imagefilter', $args);
    }

    private function _is_transparent($in)
    {
        $c = imagecolorsforindex($this->_handle, $in);
        if ($c['alpha'] >= 127) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Crop the current image, removing the transparent background area
     * @return resource
     */
    public function crop($pad = null)
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
                    if (!isset($ymin)) {
                        $ymin = $iy;
                    }
                    $ymax = $iy;
                    if ($first) {
                        $ix = $xmax;
                        $first = false;
                    }
                }
            }
        }

        // The new width and height of the image. (not including padding)
        $imw = 1 + $xmax - $xmin; // Image width in pixels
        $imh = 1 + $ymax - $ymin; // Image height in pixels

        // Make another image to place the trimmed version in.
        $im2 = imagecreatetruecolor($imw + $p[1] + $p[3], $imh + $p[0] + $p[2]);

        // Make the background of the new image the same as the background of the old one.
        $transparent = imagecolorallocatealpha($im2, 255, 255, 255, 127);
        imagefill($im2, 0, 0, $transparent);

        // Copy it over to the new image.
        imagecopy($im2, $this->_handle, $p[3], $p[0], $xmin, $ymin, $imw, $imh);

        // To finish up, we replace the old image which is referenced.
        $this->_handle = $im2;
    }

    /**
     * Save the current image on the specified path
     *
     * @param string        $path           Path to the saved file, NULL to send it to the browser
     * @param int|boolean   $quality        Lossy quality of the saved file. TRUE for maximum quality and LOSSLESS optimization
     * @param boolean|array $optimize       Optimize image after save. It can be an array of Tinyfier_Image_Optimizer::process settings
     * @param boolean       $check_is_equal Check, before save, that the file exists and it's equal to the current image (so it doesnt have to optimize again)
     * @param mixed         $format         Output format for the image, NULL to detect it from the file name
     *
     * @return boolean
     * @warning Optimization is not available when the image is sent to the browser
     */
    public function save($path, $quality = 85, $optimize = true, $check_is_equal = false, $format = null)
    {
        //Check if image exists and is equal
        if ($check_is_equal && file_exists($path) && self::equal($this, $path)) {
            return false; //Same images, don't overwrite
        }
        if (!isset($format)) {
            $format = str_replace('.', '', strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        }

        //Save image
        switch ($format) {
            case 'jpg':
            case 'jpeg':
            case IMAGETYPE_JPEG:
                $this->set_bg_color();
                $success = imagejpeg($this->_handle, $path, $optimize || $quality === true ? 100 : $quality);
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

            default:
                throw new InvalidArgumentException("Unrecognized format '$format'");
        }

        //Optimize
        if ($optimize && $path != null && $success) {
            $optimizer = new Tinyfier_Image_Optimizer();
            $optimizer->mode = $quality === true ? Tinyfier_Image_Optimizer::MODE_LOSSLESS : Tinyfier_Image_Optimizer::MODE_LOSSY;
            $optimizer->lossy_quality = $quality;
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
     *
     * @param string $format
     * @param int    $quality
     *
     * @return string
     */
    public function get($format = null, $quality = 85)
    {
        ob_start();
        $this->save(null, $quality, true, false, $format);
        return ob_get_clean();
    }

    /**
     * Get the mime type for the given format
     *
     * @param string $format
     *
     * @return string
     */
    public function mimetype($format = 'png')
    {
        return (is_string($format) ? 'image/' . $format : image_type_to_mime_type($format));
    }

    /**
     * Send the current image to the browser
     */
    public function send($format = 'png', $quality = 90)
    {
        header('Content-type: ' . $this->mimetype());
        return $this->save(null, $quality, false, false, $format);
    }

    /**
     * Check if a path refers to a valid image
     *
     * @param string $path
     *
     * @return boolean
     */
    public static function is_valid_image($path)
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

        return false;
    }

    /**
     * Do a pixel by pixel comparation of two images
     * @warning this function can take several minutes on large files
     *
     * @param mixed $image_a Path or handle to the first image
     * @param mixed $image_b Path or handle to the second image
     *
     * @return bool
     */
    public static function equal($image_a, $image_b)
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
                    if ($color_b['alpha'] != 0 || $color_b['alpha'] != 0) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

}
