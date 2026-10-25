<?php
namespace Baja\Site;

use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;
use Baja\Results\Column;
use Baja\Results\ResultRow;

class Template
{
    static function printHeader($title, $stickyHeader = true) {
        ?>
        <!DOCTYPE html>
        <html>
        <head><?php self::printGA(); ?>
            <link rel="manifest" href="/manifest.json">
            <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
            <script>
                var tagPrefix = <?= Html::js(EventoQuery::getCurrentEvent()->getEventoId() . '_') ?>;
                window.OneSignalDeferred = window.OneSignalDeferred || [];
                OneSignalDeferred.push(async function(OneSignal) {
                    console.log("OneSignal context received, starting init...");

                    // Check service worker support
                    if ('serviceWorker' in navigator) {
                        console.log("Service Worker is supported");

                        // Check if already registered
                        const registrations = await navigator.serviceWorker.getRegistrations();
                        console.log("Existing service worker registrations:", registrations.length);

                        // Try to register manually if needed
                        if (registrations.length === 0) {
                            console.log("No service workers found, attempting to register...");
                            try {
                                const registration = await navigator.serviceWorker.register('/OneSignalSDKWorker.js', {
                                    scope: '/'
                                });
                                console.log("Service worker registered successfully:", registration);
                            } catch (err) {
                                console.error("Service worker registration failed:", err);
                            }
                        }
                    } else {
                        console.error("Service Worker is not supported in this browser!");
                    }

                    await OneSignal.init({
                        appId: "2d1b50b2-362f-49c1-a854-3c8c7e0db587",
                        serviceWorkerPath: "/OneSignalSDKWorker.js",
                        allowLocalhostAsSecureOrigin: true,
                        notifyButton: { enable: false }
                    });
                    console.log("OneSignal initialized");

                    // Set up event listener
                    OneSignal.User.PushSubscription.addEventListener('change', function (subscription) {
                        console.log("Template: subscriptionChange", subscription);
                        var optedIn = subscription.current ? subscription.current.optedIn : false;
                        if (optedIn) {
                            OneSignal.User.addTag({ [tagPrefix + "psa"]: "1" });
                        } else {
                            OneSignal.User.deleteTag(tagPrefix + "psa");
                        }
                    });
                });
            </script>
            <title><?= Html::e(EventoQuery::getCurrentEvent()->getTitulo() . ($title ? " - ".$title : "")) ?></title>
            <meta name="viewport" content="width=device-width" />
            <meta charset="UTF-8" />
            <script src="js/jquery-latest.min.js"></script>
            <script src="js/jquery.tablesorter.js"></script>
            <script src="js/jquery.tablesorter.widgets.js"></script>
            <script src="js/menu.js"></script>
            <link class="theme" rel="stylesheet" href="css/theme.blue.css?version=3">
            <link class="theme" rel="stylesheet" href="css/slider.css">
            <link rel="icon" href="img/baja.png" type="image/png">
            <!--[if IE]>
            <script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
            <![endif]-->
        </head>
        <body class="body-blue">
        <?php
        self::printMenu();
    }

    static function printHeaderTournament($title) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="manifest" href="/manifest.json">
            <title><?= Html::e(EventoQuery::getCurrentEvent()->getTitulo() . ($title ? " - ".$title : "")) ?></title>
            <meta name="viewport" content="width=device-width" />
            <meta charset="UTF-8" />
            <meta http-equiv="refresh" content="5" />
            <link rel="stylesheet/less" type="text/css" href="css/tournament.less">
            <script src="https://cdn.jsdelivr.net/npm/less" ></script>
            <link rel="icon" href="img/baja.png" type="image/png">
            <!--[if IE]>
            <script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
            <![endif]-->
        </head>
        <body class="body-blue">
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

    // Judges and the public get the same menu, linking to prova.php: the
    // same URL serves judges the live page (docs/results-caching.md).
    static function printMenu($mobile = false) {
        ?>
        <div style="background: #004785;">
        <div class="top-header">
            <p id="full_titulo" style="color:white; font-size:22px; font-weight:bold; line-height: 42px;">
                <?= Html::e(EventoQuery::getCurrentEvent()->getNome()) ?>
            </p>
            <?php if(!EventoQuery::getCurrentEvent()->getFinalizado()) { ?>
                <span style='font-size: 20px;  line-height: 20px;font-weight:bold; color: yellow '>Resultados não oficiais</span>
            <?php } ?>
            <div style='position:absolute; left:10px; top:10px'>
                <a href='/'><img src='img/baja_grande.png' style='min-width: 100px'  class='logo'></a>
            </div>
            <div style='position:absolute; right:10px; top:10px'>
                <img src='img/sae.png' style='max-width: 100px' class='logo'>
            </div>
        </div>

        <div class="container" style="color:white; font-size:18px; font-weight:bold; ">
            <div style="float: left"><a class="toggleMenu" href="#"><img src='img/menu.png'></a></div>
            <div id="cell_titulo" style="display: table-cell; vertical-align: middle; height: 50px; line-height: 22px;">
                <?= Html::e(EventoQuery::getCurrentEvent()->getNome()) ?>
            </div>
            <ul class="nav" style="display: none;">
                <?php
                ResultadoQuery::create()->findByEventoId(EventoQuery::getCurrentEvent()->getEventoId()); //cache
                foreach ((array)EventoQuery::getCurrentEvent()->getMenu() as $item) {
                    if (is_object($item)) {
                        foreach ($item as $name=>$subitem) {
                            if (is_array($subitem) || is_object($subitem)) {
                                echo '<li class="first-level"><a id="'.Html::e($name).'" href="#">'.Html::e($name).'</a><ul>';
                                foreach ($subitem as $k=>$v) {
                                    if (strstr($v, "php") === false) {
                                        $resultado = ResultadoQuery::create()->findPk($v);
                                        $nome = $resultado ? $resultado->getNome() : $v;
                                        echo '<li><a id="' . Html::e($v) . '" href="prova.php?id=' . Html::e($v) . '">' . Html::e($nome) . '</a></li>';
                                    } else {
                                        echo '<li><a id="' . Html::e($k) . '" href="' . Html::e($v) . '">' . Html::e($k) . '</a></li>';
                                    }
                                }
                                echo '</ul></li>';
                            } else {
                                echo '<li class="first-level"><a id="'.Html::e($name).'" href="'.Html::e($subitem).'">'.Html::e($name).'</a></li>';
                            }
                        }
                    } else {
                        $resultado = ResultadoQuery::create()->findPk($item);
                        $nome = $resultado ? $resultado->getNome() : $item;
                        echo '<li><a id="'.Html::e($item).'" href="prova.php?id='.Html::e($item).'">'.Html::e($nome).'</a></li>';
                    }
                }
                ?>
                <!--li><a id="contato" href="contato.php">Contato</a></li-->
                <li><a id="notificacoes" href="notificacoes.php">Notificações</a></li>
                <li><a id="relogio" href="clock.php">Relógio Oficial</a></li>
                <li><a id="Arquivo" href="#">Arquivo</a><ul>
                <?php
                $eventos = EventoQuery::create()->filterByAtivo(1)->orderByTipo()->orderByAno()->find();
                $tipo = -1;
                foreach ($eventos as $evento) {
                    if ($tipo != $evento->getTipo()) {
                        if ($tipo != -1) echo '</ul></li>';
                        echo '<li><a id="' . Html::e($evento->getTipo()) . '" href="#">' . Html::e($evento->getTipo()) . '</a><ul>';
                        $tipo = $evento->getTipo();
                    }
                    echo '<li><a id="'.Html::e($evento->getEventoId()).'" href="/'.Html::e($evento->getEventoId()).'">'.Html::e($evento->getAno()).'</a></li>';
                }
                ?>
                </ul></li>
            </ul>
        </div>
        </div>
        <?php
        // The query string comes from the visitor, and a public page is
        // cached for everyone who asks for that URL: encoded for a script
        // block, never printed raw.
        $selector = "[href='" . substr($_SERVER['SCRIPT_NAME'], 1) . "'],[href$='?" . ($_SERVER['QUERY_STRING'] ?? '') . "']";
        ?>
        <script id="js"> selectItem($(<?= json_encode($selector, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)); </script>
        <?php
    }

    // A header is text typed by an admin: escaped (Baja\Site\Html). So a
    // header written with markup, like "Pontos <b>sem</b> Finais", now shows
    // the tags as text; the header itself should be fixed.
    public static function printColumnHeader($header, $size = null, $sort = null, $hidden = false)
    {
        if ($header == "Pos") {
            echo '<th style="width:20px;'.($hidden?'display: none;':'').'"><img src="img/trofeu.png"></th>';
        } else if ($header == "Equipe") {
            echo '<th '.($hidden?'class="hidden-column"':'').'">Equipe<br /><p class="nomeEscola">Escola</p></th>';
        } else {
            echo '<th style="'.($size ? 'width: '.Html::e($size).'px;' : '').'" '.($hidden?'class="hidden-column"':'').' '.($sort ? 'class="sorter-'.Html::e($sort).'"' : '').'>'. Html::e($header) . '</th>';
        }
    }

    /**
     * One row of a public results table, one <td> per column.
     *
     * The team's number, name and school come from the database and are
     * escaped. The other cells are formula output, which is meant to hold
     * HTML (bold, colours, icons), and are printed as they are: their
     * sanitiser is BAJAAPP-35.
     *
     * @param Column[] $colunas
     */
    public static function printResultRow(array $colunas, ResultRow $row)
    {
        echo "<tr>";
        foreach ($colunas as $c) {
            if ($c->val == "EquipeNum") {
                echo '<td>' . Html::e($row->team->number) . '</td>';
            } else if ($c->val == "Equipe") {
                echo '<td style="text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">' . Html::e($row->team->name) . '<br />';
                echo '<i class="nomeEscola">' . Html::e($row->team->school) . '</i>';
                if ($row->team->isDisqualified()){
                    echo '<br/><small style="color:red;">Desclassificada</small>';
                }
                echo '</td>';
            } else if ($c->val == "Pos") {
                $pos = $row->position;
                if ($pos === null) echo "<td>" . ResultRow::NO_POSITION . "</td>";
                else if ($pos == 1) echo "<td class='ouro'><p style='color:transparent'>" . $pos . "º</p></td>";
                else if ($pos == 2) echo "<td class='prata'><p style='color:transparent'>" . $pos . "º</p></td>";
                else if ($pos == 3) echo "<td class='bronze'><p style='color:transparent'>" . $pos . "º</p></td>";
                else echo "<td>" . $pos . "º</td>";
            } else {
                echo '<td>' . $row->display($c->key()) . "</td>";
            }
        }
        echo "</tr>";
    }
}