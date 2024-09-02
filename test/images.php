<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require dirname(dirname(__FILE__)) . '/vendor/autoload.php';

function format_size($size, $kilobyte = 1024, $format = '%size% %unit%'): string
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

if (!isset($_GET['level'])) {
    $_GET['level'] = Tinyfier_Image_Optimizer::LEVEL_NORMAL;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <title>Tinyfier image compression test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="">
    <meta name="author" content="">

    <link href="../assets_loader/tinyfier.php/test/assets/bootstrap.css?recache" rel="stylesheet">
    <link href="../assets_loader/tinyfier.php/test/assets/bootstrap-responsive.css?recache" rel="stylesheet">

    <style type="text/css">
        body {
            padding-top: 60px;
            padding-bottom: 40px;
        }

        .sidebar-nav {
            padding: 9px 0;
        }
    </style>

    <script type="text/javascript" src="../assets_loader/tinyfier.php/test/assets/js/jquery-dev.js,test.js,bootstrap.js"></script>
</head>

<body>

<div class="container">
    <div class="page-header">
        <form method="GET">
            <h1>Tinyfier image compression test</h1>
            <label>Level: <select name="level" onchange="this.form.submit()">
                    <?php
                    foreach (array(
                                 Tinyfier_Image_Optimizer::LEVEL_FAST => 'Fast',
                                 Tinyfier_Image_Optimizer::LEVEL_NORMAL => 'Normal',
                                 Tinyfier_Image_Optimizer::LEVEL_HIGH => 'High',
                                 Tinyfier_Image_Optimizer::LEVEL_EXTREME => 'Extreme (slow)',
                             ) as $level => $name) {
                        ?>
                        <option value="<?= $level ?>" <?= $level == $_GET['level'] ? 'selected' : '' ?>><?= $name ?></option>
                    <?php
                    }
                    ?>
                </select></label>
        </form>
    </div>
    <?php
    $out_path = dirname(__FILE__) . '/assets/compressed';
    if (!is_dir($out_path)) {
        mkdir($out_path);
    }

    foreach (new DirectoryIterator(dirname(__FILE__) . '/assets/images') as $file) :
        if ($file->isDir() || $file->isDot()) {
            continue;
        }
        $dest = $out_path . '/' . $file->getFilename();

        if (file_exists($dest)) {
            unlink($dest);
        }
        ?>

        <h2><?= $file->getFilename() ?></h2>
        <?php
        //Compress image
        copy($file->getPathname(), $dest);
        $optimizer = new Tinyfier_Image_Optimizer();
        $optimizer->verbose = true;
        $optimizer->mode = Tinyfier_Image_Optimizer::MODE_LOSSY;
        $optimizer->level = $_GET['level'];
        $optimizer->optimize($dest);
        ?>
        <div class="row-fluid">
            <div class="span6">
                <h4>Original
                    <small><?= format_size(filesize($file->getPathname())) ?></small>
                </h4>
                <img src="<?= str_replace(dirname(__FILE__) . '/', '', $file->getPathname()) ?>"/></div>
            <div class="span6">
                <h4>Optimized
                    <small><?= format_size(filesize($dest)) ?> <?= round(100 / filesize($file->getPathname()) * filesize($dest)) ?>% of original size</small>
                </h4>
                <img src="<?= str_replace(dirname(__FILE__) . '/', '', $dest) ?>"/></div>
        </div>
    <?php endforeach; ?>
</div>


</body>
</html>