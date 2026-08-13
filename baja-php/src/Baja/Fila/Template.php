<?php
namespace Baja\Fila;

use Baja\Model\EventoQuery;

class Template
{
    static function printHeader($title, $stickyHeader = true) {
        ?>
        <!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/html" class="no-js"  lang="pt-br">
        <head><?php self::printGA(); ?>
            <meta name="viewport" content="width=device-width" />
            <meta charset="UTF-8" />
            <meta http-equiv="Content-Language" content="pt-br">
            <meta http-equiv="x-ua-compatible" content="ie=edge">
            <meta name="google" content="notranslate">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
            <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
            <link rel="icon" href="img/baja.png" type="image/png">
            <!--[if IE]>
            <script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
            <![endif]-->
            <title><?php if ($title) { echo($title); } else { echo('Filas Eletrônicas Baja SAE&nbsp;BRASIL'); } ?></title>
        </head>
        <body>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>            
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