<?php
namespace Baja\Fila;

Template::printHeader("Início");

define("FECHADA",0);
define("ABERTA",1);
define("PROG_ABRIR",2);
define("PROG_FECHAR",3);

?>

<base target="_top">
    
<div class="container">
    <h1 class="center-align" style="font-weight:bold;">Selecione uma fila para exibição:</h1><br><br>

    <table>
        <thead>
            <th>Evento</th>
            <th>Fila</th>
            <th>Status</th>
            <th>Link</th>
        </thead>
        <tbody>
            <?php
                foreach(Fila::getFilas() as $f) {
            ?>
                <tr>
                    <td><?= $f['evento_nome'] ?></td>
                    <td><?= $f['nome'] ?></td>
                    <td><?php 
                        if ($f['status'] == ABERTA) {
                            echo('Aberta');
                        } elseif ($f['status'] == PROG_ABRIR) {
                            echo('Fechada<br>Abertura programada: <span class="time">'.date_format($f['abertura_programada'], 'Y-m-d H:i:sO').'</span>');
                        } elseif ($f['status'] == PROG_FECHAR) {
                            echo('Aberta<br>Fechamento programado: <span class="time">'.date_format($f['fechamento_programado'], 'Y-m-d H:i:sO').'</span>');
                        } else {
                            echo('Fechada');
                        }
                        ?></td>
                    <td><a href="fila.php?evento=<?= $f['evento_id'] ?>&fila=<?= $f['fila_id'] ?>">LINK</a></td>
                </tr>
            <?php
                }
            ?>
        </tbody>
    </table>

    </br></br></br>

    <div class="row" id="proximoSection">
        <a href="filapicker.php" class="card green darken-1 col s12">
            <div class="card-content white-text center-align">
                <span style="font-size:3rem;font-weight:bold">Clique aqui para interagir com as filas</span>
            </div>            
        </a>
    </div>

</div>

<?php

echo('<script src="js/time-adjust.js"></script>');
Template::printFooter();
