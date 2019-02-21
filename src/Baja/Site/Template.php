<?php
namespace Baja\Site;

use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;

class Template
{
    static function printHeader($title, $stickyHeader = true) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="manifest" href="/manifest.json">
            <script src="https://cdn.onesignal.com/sdks/OneSignalSDK.js" async></script>
            <script>
                var OneSignal = window.OneSignal || [];
                OneSignal.push(["init", {
                    appId: "f4b8f501-88bb-49fe-b209-712ae98da3e2",
                    autoRegister: true,
                    notifyButton: {
                        enable: false /* Set to false to hide */
                    },
                    welcomeNotification: {
                        "title": "Baja SAE Brasil Online",
                        "message": "Obrigado por se inscrever!",
                        "url": "<?= EventoQuery::getCurrentEvent()->getEventoId() ?>/notificacoes.php"
                    },
                    persistNotification: false,
                    safari_web_id: "web.onesignal.auto.246fdfe2-a404-4d40-aa8a-d2b211d431d5"
                }]);
                var tagPrefix = '<?= EventoQuery::getCurrentEvent()->getEventoId() ?>_';
                OneSignal.push(function() {
                    OneSignal.on('subscriptionChange', function (isSubscribed) {
                        if (isSubscribed) OneSignal.push(["sendTag", tagPrefix + "psa", 1])
                    });
                });
            </script>
            <title><?= EventoQuery::getCurrentEvent()->getTitulo() . ($title ? " - ".$title : "") ?></title>
            <meta name="viewport" content="width=device-width" />
            <meta charset="UTF-8" />
            <script src="js/jquery-latest.min.js"></script>
            <script src="js/jquery.tablesorter.js"></script>
            <script src="js/jquery.tablesorter.widgets.js"></script>
            <script src="js/menu.js"></script>
            <link class="theme" rel="stylesheet" href="css/theme.blue.css">
            <link class="theme" rel="stylesheet" href="css/slider.css">
            <link rel="icon" href="img/baja.png" type="image/png">
            <!--[if IE]>
            <script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
            <![endif]-->
        </head>
        <body class="body-blue">
        <?php
        self::printGA();
        self::printMenu();
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

    static function printMenu($mobile = false) {
        ?>
        <div style="background: #004785;">
        <div class="top-header">
            <p id="full_titulo" style="color:white; font-size:22px; font-weight:bold; line-height: 42px;">
                <?= EventoQuery::getCurrentEvent()->getNome() ?>
            </p>
            <?php if(!EventoQuery::getCurrentEvent()->getFinalizado()) { ?>
                <span style='font-size: 20px;  line-height: 20px;font-weight:bold; color: yellow '>Resultados não oficiais</span>
            <?php } ?>
            <div style='position:absolute; left:10px; top:10px'>
                <a href='/'><img src='img/baja_grande.png' style='min-width: 100px'  class='logo'></a>
            </div>
            <div style='position:absolute; right:10px; top:10px'>
                <img src='img/sae.png' style='max-width: 100px' class='logo'><br />
                <img src='img/petrobras.png' style='max-width: 120px' class='logo'>
            </div>
        </div>

        <div class="container" style="color:white; font-size:18px; font-weight:bold; ">
            <div style="float: left"><a class="toggleMenu" href="#"><img src='img/menu.png'></a></div>
            <div id="cell_titulo" style="display: table-cell; vertical-align: middle; height: 50px; line-height: 22px;">
                <?= EventoQuery::getCurrentEvent()->getNome() ?>
            </div>
            <ul class="nav" style="display: none;">
                <?php
                ResultadoQuery::create()->findByEventoId(EventoQuery::getCurrentEvent()->getEventoId()); //cache
                foreach ((array)EventoQuery::getCurrentEvent()->getMenu() as $item) {
                    if (is_object($item)) {
                        foreach ($item as $name=>$subitem) {
                            if (is_array($subitem) || is_object($subitem)) {
                                echo '<li class="first-level"><a id="'.$name.'" href="#">'.$name.'</a><ul>';
                                foreach ($subitem as $k=>$v) {
                                    if (strstr($v, "php") === false) {
                                        $resultado = ResultadoQuery::create()->findPk($v);
                                        $nome = $resultado ? $resultado->getNome() : $v;
                                        echo '<li><a id="' . $v . '" href="prova.php?id=' . $v . '">' . $nome . '</a></li>';
                                    } else {
                                        echo '<li><a id="' . $k . '" href="' . $v . '">' . $k . '</a></li>';
                                    }
                                }
                                echo '</ul></li>';
                            } else {
                                echo '<li class="first-level"><a id="'.$name.'" href="'.$subitem.'">'.$name.'</a></li>';
                            }
                        }
                    } else {
                        $resultado = ResultadoQuery::create()->findPk($item);
                        $nome = $resultado ? $resultado->getNome() : $item;
                        echo '<li><a id="'.$item.'" href="prova.php?id='.$item.'">'.$nome.'</a></li>';
                    }
                }
                ?>
                <li><a id="contato" href="contato.php">Contato</a></li>
                <li><a id="notificacoes" href="notificacoes.php">Notificações</a></li>
                <li><a id="relogio" href="clock.php">Relógio Oficial</a></li>
                <li><a id="Arquivo" href="#">Arquivo</a><ul>
                <?php
                $eventos = EventoQuery::create()->filterByAtivo(1)->orderByTipo()->orderByAno()->find();
                $tipo = -1;
                foreach ($eventos as $evento) {
                    if ($tipo != $evento->getTipo()) {
                        if ($tipo != -1) echo '</ul></li>';
                        echo '<li><a id="' . $evento->getTipo() . '" href="#">' . $evento->getTipo() . '</a><ul>';
                        $tipo = $evento->getTipo();
                    }
                    echo '<li><a id="'.$evento->getEventoId().'" href="/'.$evento->getEventoId().'">'.$evento->getAno().'</a></li>';
                }
                ?>
                </ul></li>
            </ul>
        </div>
        </div>
        <script id="js"> selectItem($("[href='<?= substr($_SERVER['SCRIPT_NAME'], 1) ?>'],[href$='<?= "?".$_SERVER['QUERY_STRING'] ?>']")); </script>
        <?php
    }

    public static function printColumnHeader($header, $size = null, $sort = null)
    {
        if ($header == "Pos") {
            echo '<th style="width:20px;"><img src="img/trofeu.png"></th>';
        } else if ($header == "Equipe") {
            echo '<th>Equipe<br /><p class="nomeEscola">Escola</p></th>';
        } else {
            echo '<th '.($size ? 'style="width: '.$size.'px"' : '').' '.($sort ? 'class="sorter-'.$sort.'"' : '').'>'. $header . '</th>';
        }
    }
}