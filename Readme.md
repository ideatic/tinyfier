# Tinyfier v0.2
#### <http://www.digitalestudio.es/proyectos/tinyfier/>

`Tinyfier` is a CSS and Javascript preprocessing and minification tool.

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

    .login {
        background: sprite('images/user_go.png', 'user') no-repeat;
    }

    .logout {
        background: sprite('images/user_delete.png', 'user') no-repeat;
    }

#### Gradient generator

Tinyfier include tools to generate CSS3-compatible gradients with backward 
compatibility with old browsers (through the generation of the equivalent 
images)
    
    header {
        background: gradient('vertical', @header_start, @header_middle 50%, @header_end, 1px, @header_height);
    }

#### Image embedding

You can also embed images in the document with the command `inline`

    #gplus {
        background: inline('images/social/gplus.png');
    }