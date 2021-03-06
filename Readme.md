# Tinyfier
#### <http://www.tinyfier.com>

`Tinyfier` is a complete suite for compressing, preprocessing, and optimizing HTML/Javascript/CSS and Images.

### Usage

#### Optimization on-the-fly (assets_loader)

With `Tinyfier` you can combine multiple CSS or Javascript files, add extra functionality to CSS (using LESS), remove unnecessary whitespace and comments, and serve them with gzip encoding and optimal client-side cache headers.

For compress and combine your javascript and stylesheets files, all that you
need to do is replace the original URL:

>http://example.com/static/stylesheet.css

By this:

>http://example.com/static/tinyfier/tinyfier.php/stylesheet.css

You can also join multiple files into a larger one, reducing the number of
HTTP request and making your application fly!

>http://example.com/static/tinyfier/tinyfier.php/main.css,user.css,print.css

Also, if you want to pass extra variables to CSS parser, you can do it by adding
it into the URL, for example:

>http://example.com/static/tinyfier/tinyfier.php/stylesheet.css,base_color=%23ff0000
>http://example.com/static/tinyfier/tinyfier.php/stylesheet.css,height=450

### Javascript

`Tinyfier` uses the Google Closure service to compile and minimize Javascript, and, if not available, 
rely on JSMinPlus for that operation.

### CSS

For CSS files, `Tinyfier` uses the [lessphp parser by `leafo`](http://leafo.net/lessphp/) for add extra functionality to css files. This include 
variables, mixins, expressions, nested blocks, etc. You can see all the available
commands in [lessphp documentation](http://leafo.net/lessphp/docs/). Also, the generated css code is optimized,  compressed and the CSS3 vendor prefix (like -*webkit* or *-moz*) are added, using [css_optimizer](https://github.com/javiermarinros/css_optimizer).

Also, Tinyfier adds even more functionality:

#### Sprites

With Tinyfier, create a css sprite it's easy and intuitive. All that you need to 
do use the function `sprite` where the first argument is the image path (relative 
to the document) and the second the name of the sprite. E.g.:

> 	  .login {
>         background: sprite('images/user_go.png', 'user') no-repeat;
>     }
>
>     .logout {
>         background: sprite('images/user_delete.png', 'user') no-repeat;
>     }

#### Gradient generator

Tinyfier include tools to generate CSS3-compatible gradients with backward 
compatibility with old browsers (through the generation of the equivalent 
images).
    
> 	  header {
>         background: gradient('vertical', @header_start_color, @header_middle_color 50%, @header_end_color, 1px, 200px);
>     }

(Remember that with lessphp you can use variables everywhere in your code!)

#### Image embedding

You can also embed images in the document (using the [Data URI scheme](http://en.wikipedia.org/wiki/Data_URI_scheme)) with the command `inline`.

>     #gplus {
>        background: inline('images/social/gplus.png');
>     }

#### Image filters

Another cool function of `Tinyfier` is the ability for work with image filters.

>     #lion:hover {
>        background: filter('images/lion.jpg', 'brightness', 50%);
>     }

Internally, `Tinyfier` uses the php function [imagefilter](http://www.php.net/manual/function.imagefilter.php), so you can use all the filters available for it (negate, grayscale, brightness, blur, pixelate and more).

#### Resize images

If you have to show your image or sprite in differents size, it's as easy as use:

>     #lion:hover {
>        background: resize('images/lion.jpg', 50%);
>     }

The filter `resize` can take up to 4 arguments: image url, width (in either px or %), height, and a boolean value than enables or disables aspect ratio (true / false)

#### More

Please, look in the test file for more examples for using Tinyfier in your project. It's really easy!