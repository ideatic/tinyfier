function increase() {
    change_font_size(1.25);
}

function decrease() {
    change_font_size(1 / 1.25);
}

function change_theme(base_color) {
    $('#theme').attr('href', '../tinyfier/tinyfier.php/test/style.css,base=%23' + base_color);
}

function change_font_size(incr) {
    var ourText = $('body');
    var currFontSize = ourText.css('fontSize');
    var finalNum = parseFloat(currFontSize, 10);
    var stringEnding = currFontSize.slice(-2);
    ourText.animate({
        fontSize: (finalNum * incr) + stringEnding
    }, 600);
}

$(document).ready(function () {
    $('div[class]').each(function () {
        $(this).attr('title', $(this).attr("class"));
    });
});