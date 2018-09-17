<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;

class Template
{
    static function printHeader($title, $stickyHeader = true) {
        ?>
        <!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/html">
        <head>
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
        self::printGA();
    }

    static function printFooter() {
        ?>
        </body>
        </html>
        <?php
    }

    static function printGA() {
        ?>
        <script>
            (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
                    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
                m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
            })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

            ga('create', 'UA-92791788-1', 'auto');
            ga('send', 'pageview');

        </script>
        <?php
    }
}