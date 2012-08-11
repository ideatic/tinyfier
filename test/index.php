<?php require_once '../tinyfier/css/css.php'; ?>
<!DOCTYPE html>
<html>
    <head>
        <title>css_optimizer test</title>
        <link rel="stylesheet" href="CodeMirror/codemirror.css">
        <script src="CodeMirror/codemirror.js"></script>
        <link type="text/css" rel="stylesheet" href="../tinyfier/tinyfier.php/test/index.less" />
    </head>
    <body>
        <header>
            <h1><a href="">css_optimizer</a></h1>
            <h2>Powered by <a href="https://github.com/javiermarinros/Tinyfier" target="_blank">Tinyfier</a></h2>
        </header>
        <?php $process_data = do_optimization(); ?>
        <form method="post">
            <div id="editor">
                <div>
                    <label for="source">Paste your CSS</label>
                    <textarea id="source" name="source"><?php echo isset($_POST['source']) ? $_POST['source'] : file_get_contents('index.less') ?></textarea>
                    <div class="tools">
                    <input type="button" onclick="autoFormatSelection();" value="Format" />
                    </div>
                </div>
                <div>
                    <label for="result">Prefixed CSS</label>
                    <textarea id="result" name="result"><?php echo isset($process_data['css']) ? $process_data['css'] : ''; ?></textarea>
                </div>
            </div>
            <div id="extra">
                <div>
                    <h3>Options</h3>
                    <div>
                        <input type="checkbox" id="optimize" name="optimize" <?php echo $process_data['settings']['optimize'] ? 'checked' : ''; ?> /><label for="optimize">Optimize</label>
                    </div>
                    <div> 
                        <input type="checkbox" id="compress" name="compress" <?php echo $process_data['settings']['compress'] ? 'checked' : ''; ?> /><label for="compress" title="Compress the code, removing whitespaces and unnecessary characters">Compress code</label>
                    </div>
                    <div>
                        <input type="checkbox" id="extra_optimize" name="extra_optimize" <?php echo $process_data['settings']['extra_optimize'] ? 'checked' : ''; ?> /><label for="extra_optimize" title="Apply some extra optimizations, like reorder selectors and rules in order to improve gzip compression ratio">Extra optimizations (may be unsafe)</label>
                    </div>
                    <div>
                        <input type="checkbox" id="remove_ie_hacks" name="ie_compatible" <?php echo $process_data['settings']['ie_compatible'] ? 'checked' : ''; ?> /><label for="ie_compatible" title="Try to generate a CSS compatible with old IE versions">IE compatible</label>
                    </div>
                </div>  
                <div>
                    <h3>Prefix</h3>
                    <div>
                        <div>
                            <input type="checkbox" id="prefix-webkit" name="prefix[webkit]" <?php echo $process_data['settings']['prefix']['webkit'] ? 'checked' : ''; ?> /><label for="prefix-webkit" title="Add prefix for webkit-based browser such as Chrome or Safari">Webkit</label>
                        </div>
                        <div>
                            <input type="checkbox" id="prefix-mozilla" name="prefix[mozilla]" <?php echo $process_data['settings']['prefix']['mozilla'] ? 'checked' : ''; ?> /><label for="prefix-mozilla" title="Add prefix for Mozilla Firefox">Firefox</label>
                        </div>
                        <div> 
                            <input type="checkbox" id="prefix-opera" name="prefix[opera]" <?php echo $process_data['settings']['prefix']['opera'] ? 'checked' : ''; ?> /><label for="prefix-opera" title="Add prefix for Opera Browser">Opera</label>
                        </div>
                        <div>
                            <input type="checkbox" id="prefix-microsoft" name="prefix[microsoft]" <?php echo $process_data['settings']['prefix']['microsoft'] ? 'checked' : ''; ?> /><label for="prefix-microsoft" title="Add prefix for Internet Explorer">Internet Explorer</label>
                        </div>
                    </div>
                </div>   
                <?php if (isset($process_data['css'])): ?>
                    <div>
                        <h3>Statistics</h3>
                        <ul>
                            <li>Original size: <?php echo readable_size(strlen($process_data['source'])) ?> (<?php echo strlen(gzencode($process_data['source'], 9)) ?> bytes gzipped)</li>
                            <li>Final size: <?php echo readable_size(strlen($process_data['css'])) ?> (<?php echo strlen(gzencode($process_data['css'], 9)) ?> bytes gzipped)</li>
                            <li>Difference: <strong><?php printf('%s (%+g%%)', readable_size(strlen($process_data['css']) - strlen($process_data['source']), true), round(strlen($process_data['css']) / strlen($process_data['source']) * 100, 2)) ?></strong></li>
                        </ul>
                        <ul>
                            <li>Duration: <?php echo readable_time($process_data['execution_time']) ?></li>
                        </ul>
                    </div>     
                <?php endif ?>
            </div>
            <div id="submit">
                <input type="submit" />
            </div>            
        </form>
        <?php
        if (!empty($process_data['errors'])) {
            echo '<h3>Errors</h3>';
            echo '<ul>';
            foreach ($process_data['errors'] as $error) {
                echo "<li>$error</li>";
            }
            echo '</ul>';
        }
        ?>
        <script>
            var settings={
                mode: "text/css",
                matchBrackets: true
            };
            var source=CodeMirror.fromTextArea(document.getElementById("source"),settings);
            settings['readOnly']=true;
            var processed= CodeMirror.fromTextArea(document.getElementById("result"),settings);
            
            function autoFormatSelection() {
                CodeMirror.commands["selectAll"](source); 
                source.autoFormatRange(source.getCursor(true), source.getCursor(false));
            } 
        </script>
    </body>
</html>
<?php

function do_optimization() {
    $data = array();

    if (empty($_POST)) {
        //Default settings
        $data['settings'] = CSS::default_settings();
    } else {
        //User settings
        $data['settings'] = array(
            'compress' => isset($_POST['compress']),
            'optimize' => isset($_POST['optimize']),
            'extra_optimize' => isset($_POST['extra_optimize']),
            'ie_compatible' => isset($_POST['ie_compatible']),
            'prefix' => array(),
        );
        foreach (array('webkit', 'mozilla', 'opera', 'microsoft') as $type) {
            $data['settings']['prefix'][$type] = isset($_POST['prefix'][$type]);
        }
    }

    $data['errors'] = '';

    if (!isset($_POST['source']))
        return $data;

    $data['source'] = $_POST['source'];

    $start = microtime(true);
    $data['css'] = optimize($_POST['source'], $data['settings'], $data['errors']);
    $data['execution_time'] = microtime(true) - $start;

    return $data;
}

function optimize($css, $settings, &$errors = null) {

    $result = CSS::process($css, $settings);

    return $result;
}

function readable_time($time) {
    if ($time > 60) {
        $min = floor($time / 60);
        $sec = round($time) % 60;
        return "{$min}m {$sec}s";
    } elseif ($time > 1) {
        return round($time, 3) . ' s';
    } elseif ($time > 0.001) {
        return round($time * 1000) . ' ms';
    } else {
        return round($time * 1000000) . ' &micro;s';
    }
}

function readable_size($bytes, $sign = false, $precission = 2) {
    if ($bytes > 1000000000) {
        $count = round($bytes / 1000000000, $precission);
        $unit = 'GB';
    } elseif ($bytes > 1000000) {
        $count = round($bytes / 1000000, $precission);
        $unit = 'MB';
    } elseif ($bytes > 1000) {
        $count = round($bytes / 1000, $precission);
        $unit = 'KB';
    } else {
        $count = $bytes;
        $unit = 'bytes';
    }

    return sprintf($sign ? '%+g %s' : '%g %s', $count, $unit);
}