# Tinyfier v0.2
#### <http://www.digitalestudio.es/proyectos/tinyfier/>

`Tinyfier` is a CSS and Javascript preprocessing and minification tool. 
It combines multiple CSS or Javascript files, removes unnecessary whitespace and comments, and serves them with gzip encoding and optimal client-side cache headers.

### Usage

For compress and process your javascript and stylesheets files, all that you
need to do is replace the original URL:

>http://example.com/static/stylesheet.css

By this:

>http://example.com/static/tinyfier/f=stylesheet.css

You can also join multiple files into a larger one, reducing the number of
HTTP request and making your application fly!

>http://example.com/static/tinyfier/f=main.css,user.css,print.css

Also, if you want to pass extra variables to the LESS parser, you can do by adding
it into the URL, for example:

>http://example.com/static/tinyfier/f=stylesheet.css,base_color=%23ff0000
>http://example.com/static/tinyfier/f=stylesheet.css,height=450

### Javascript

Tinyfier uses Google Closure to compile and minimize Javascript, and, if not available, 
rely on JSMinPlus for that operation.

### CSS

Tinyfier uses lessphp for add extra functionality to css files. This include 
variables, mixins, expressions, nested blocks, etc. You can see all the available
commands in <http://leafo.net/lessphp/docs/>.

Also, Tinyfier adds more functionality:

#### Sprites

With Tinyfier, create a css sprite it's easy and intuitive. All that you need to 
do use the function `sprite` where the first argument is the image path (relative 
to the document) and the second the name of the sprite. E.g.:

> 	  .login {
>         background: sprite('images/user_go.png', 'user') no-repeat;
>     }

>     .logout {
>         background: sprite('images/user_delete.png', 'user') no-repeat;
>     }

#### Gradient generator

Tinyfier include tools to generate CSS3-compatible gradients with backward 
compatibility with old browsers (through the generation of the equivalent 
images)
    
> 	  header {
>         background: gradient('vertical', @header_start, @header_middle 50%, @header_end, 1px, @header_height);
>     }

#### Image embedding

You can also embed images in the document with the command `inline`

>     \#gplus {
>        background: inline('images/social/gplus.png');
>     }

#### More

Please, look in the test file for more examples for using Tinyfier in your project. It's really easy!