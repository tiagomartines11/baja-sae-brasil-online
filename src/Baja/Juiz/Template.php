<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;

class Template
{
    static function printHeader($title, $stickyHeader = true) {
        ?>
        <!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/html">
        <head><?php self::printGA(); ?>
            <meta name="viewport" content="width=device-width" />
            <meta charset="UTF-8" />
            <meta http-equiv="Content-Language" content="pt-br">
            <meta name="google" content="notranslate">
            <script src="js/jquery-latest.min.js"></script>
            <script src="js/jquery.tablesorter.js"></script>
            <script src="js/jquery.tablesorter.widgets.js"></script>
            <link class="theme" rel="stylesheet" href="css/theme.blue.css">
            <?php if (!$stickyHeader) echo '<link class="theme" rel="stylesheet" href="css/theme.blue.disable.hover.css">'; ?>
            <link rel="icon" href="img/baja.png" type="image/png">
            <!--[if IE]>
            <script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
            <![endif]-->
            <title><?= EventoQuery::getCurrentEvent()->getTitulo() ?> - <?= $title ?></title>
        </head>
        <script id="js">
            $(function(){
                var v= $("table");
                $("table").tablesorter({
                    theme : 'blue',
                    widgets: [ 'zebra' <?php if($stickyHeader) { ?>, 'stickyHeaders' <?php } ?>]
                });
            });
        </script>
        <body>
        <?php
    }

    static function printFooter() {
        ?>
        </body>
        </html>
        <?php
    }

    static function printGA() {
        ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-KBKCF1HW4R"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());

          gtag('config', 'G-KBKCF1HW4R');
        </script>
        <?php
    }
}