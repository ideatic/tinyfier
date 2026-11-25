# Tinyfier

`Tinyfier` is a PHP suite to compress, preprocess and optimize HTML, JavaScript, CSS and images.

- **CSS**: LESS preprocessing (sprites, gradients, inline images, image filters and resizing), then
  optimization, compression and vendor prefixes with [css-optimizer](https://github.com/ideatic/css-optimizer).
- **JavaScript**: minified with a local compressor (terser, esbuild or UglifyJS) when one is installed.
- **HTML**: whitespace and comment removal, plus inline `<style>` and `<script>` compression.
- **Images**: resize, crop, filters and format changes with GD (PNG, JPEG, GIF, WebP, AVIF), and
  lossless or lossy optimization with local tools.

## Requirements

- PHP 8.5 or newer, with the `gd` extension.
- `proc_open` must be enabled to use the external JavaScript and image tools. Without it, Tinyfier still
  works, but it does not use them.
- Optional external tools, found in the `PATH` (see below).

## Installation

```bash
composer require ideatic/tinyfier
```

## Usage

### JavaScript

```php
$errors = [];
$minified = Tinyfier_JS_Tool::process($source, ['pretty' => false], $errors);
```

Tinyfier does not bundle a JavaScript compressor. It tries these commands, in this order, and uses the
first one that it finds in the `PATH`:

1. [terser](https://terser.org/) (`npm install -g terser`)
2. [esbuild](https://esbuild.github.io/) (`npm install -g esbuild`)
3. [UglifyJS](https://github.com/mishoo/UglifyJS) (`npm install -g uglify-js`)

If no compressor is available, or all of them fail, the code is returned without changes and the reason
is added to `$errors`.

| Setting             | Default                              | Description                                                         |
|---------------------|--------------------------------------|---------------------------------------------------------------------|
| `pretty`            | `false`                              | `true` returns the code without changes.                            |
| `local`             | `true`                               | `false` disables the local compressors.                             |
| `local_compressors` | `['terser', 'esbuild', 'uglifyjs']`  | Commands to try, in order. A name is looked up in the `PATH`; an absolute path is used as it is. |
| `local_timeout`     | `30`                                 | Maximum seconds for each compressor.                                |

PHP-FPM and web servers often run with a short `PATH`. If the compressor is not found there, give its
absolute path in `local_compressors`, for example `['/usr/local/bin/terser']`.

The Google Closure web service and the `gclosure`, `level` and `external_services` settings were removed:
the service no longer exists.

### CSS

```php
$css = Tinyfier_CSS_Tool::process_file('/var/www/static/main.less', [
    'url_path'   => '/static/main.less',
    'cache_path' => '/var/www/static/cache',
    'cache_url'  => '/static/cache/',
]);

$css = Tinyfier_CSS_Tool::process('a { color: #ff0000 }', ['less' => false]);
```

| Setting           | Default     | Description                                                                 |
|-------------------|-------------|-----------------------------------------------------------------------------|
| `less`            | `true`      | Process the code with lessphp.                                              |
| `absolute_path`   | `''`        | Absolute path of the file (set by `process_file`).                          |
| `url_path`        | `''`        | URL of the file, used to rewrite relative `url()` values.                   |
| `cache_path`      | `''`        | Folder for the generated images (sprites, gradients, filters).             |
| `cache_url`       | `'/cache/'` | Public URL of `cache_path`.                                                 |
| `compress`        | `true`      | Remove whitespace. `false` gives readable output.                           |
| `optimize`        | `true`      | Safe optimizations (colors, units, shorthands).                             |
| `extra_optimize`  | `false`     | Merge adjacent rules. Safe, but it changes the rule structure.              |
| `optimize_images` | `true`      | Optimize the generated images. An array is used as image optimizer settings. |
| `lossy_quality`   | `75`        | Quality of the generated lossy images. `true` means lossless.               |
| `data`            | `null`      | Variables for the LESS code.                                                |
| `prefixes`        | `'all'`     | Vendor prefixes to add (`'all'`, `''`, or a list such as `'webkit,mozilla'`). |

> **Known issue:** `leafo/lessphp` 0.5.0 does not parse on PHP 8. Until it is replaced, use
> `'less' => false`, or LESS files cause a fatal error.

#### LESS functions

Sprites: all images with the same sprite name go into one image.

```less
.login  { background: sprite('images/user_go.png', 'user') no-repeat; }
.logout { background: sprite('images/user_delete.png', 'user') no-repeat; }
```

Gradients: the CSS gradient, plus an image for old browsers.

```less
header { background: gradient('vertical', @start, @middle 50%, @end, 1px, 200px); }
```

Inline images, as [data URIs](https://en.wikipedia.org/wiki/Data_URI_scheme) (PNG, GIF, JPEG, WebP, AVIF):

```less
#gplus { background: inline('images/social/gplus.png'); }
```

Image filters, with the [imagefilter](https://www.php.net/manual/function.imagefilter.php) filters
(negate, grayscale, brightness, blur, pixelate...):

```less
#lion:hover { background: filter('images/lion.jpg', 'brightness', 50); }
```

Resize: image URL, width (px or %), height, and keep aspect ratio (`true` or `false`):

```less
#lion { background: resize('images/lion.jpg', 50%); }
```

### HTML

```php
$html = Tinyfier_HTML_Tool::process($html, [
    'compress_all' => true,          // also compress inline CSS and JS
    'css'          => [],            // settings for Tinyfier_CSS_Tool (inline CSS never uses LESS)
    'js'           => [],            // settings for Tinyfier_JS_Tool
    'markers'      => ['<?'],        // inline code that contains one of these is not compressed
]);
```

### Images

```php
$image = new Tinyfier_Image_Tool('photo.jpg');
$image->resize(800);
$image->save('photo.webp', 80);       // format from the extension; quality 80
$image->save('icon.png', true);       // true = lossless

Tinyfier_Image_Optimizer::process('photo.jpg', ['mode' => Tinyfier_Image_Optimizer::MODE_LOSSY, 'lossy_quality' => 80]);
```

Tinyfier does not bundle image tools. The optimizer uses only the tools that it finds in the `PATH`, and
skips the steps whose tool is missing:

| Format | Tools                                      |
|--------|--------------------------------------------|
| JPEG   | `jpegtran`                                 |
| PNG    | `pngquant` (lossy mode), `optipng`, `pngout` |
| GIF    | `gifsicle`                                 |
| WebP, AVIF | None. GD applies the quality when it saves the file. |

`nice` is used to run the tools with low priority when it is available. Each tool stops after `timeout`
seconds (default 60).

WebP and AVIF need a GD build with that support (`imagewebp`, `imageavif`). If it is missing, Tinyfier
throws a `RuntimeException`.

## Assets loader

`assets_loader/tinyfier.php` combines and compresses CSS and JavaScript files on the fly, and serves them
with gzip and cache headers. Replace the URL of a file:

```
http://example.com/static/stylesheet.css
```

with:

```
http://example.com/static/tinyfier/tinyfier.php/stylesheet.css
```

Combine several files in one request:

```
http://example.com/static/tinyfier/tinyfier.php/main.css,user.css,print.css
```

Pass variables to the LESS code:

```
http://example.com/static/tinyfier/tinyfier.php/stylesheet.css,base_color=%23ff0000
```

## Development

```bash
composer install
phpstan analyse   # settings in phpstan.neon
```

## License

MIT
