<?php

namespace Baja\Fila;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\SenhaQuery;

use Baja\Session;

$filas = Fila::getFilasUsuario(Session::getCurrentUser()->getUsername());

foreach($filas as $k=>$f){

    $filas[$k]['evento_nome'] = EventoQuery::create()->findPk($f['evento_id'])->getTitulo();
    $filas[$k]['fila_nome'] = FilaQuery::create()->filterByEventoId($f['evento_id'])->filterByFilaId($f['fila_id'])->findOne()->getNome();
}

Template::printHeader("Início");

?>

<base target="_top">
    
<div class="container">

    <h1 class="center-align" style="font-weight:bold;">Selecione uma fila para interagir:</h1><br><br>

    <table>
        <thead>
            <th>Evento</th>
            <th>Fila</th>
            <th>Carro</th>
        </thead>
        <tbody>
            <?php
                foreach($filas as $f) {
            ?>
                <tr>
                    <td><?= $f['evento_nome'] ?></td>
                    <td><?= $f['fila_nome'] ?></td>
                    <td><?php 
                        if ($f['permissao'] == 'ADMIN') {
                            echo('<a href="filaadmin.php?evento='.$f['evento_id'].'&fila='.$f['fila_id'].'">Administrar</a>');
                        } else {
                            echo('<a href="senha.php?evento='.$f['evento_id'].'&fila='.$f['fila_id'].'&carro='.$f['permissao'].'">'.$f['permissao'].'</a>');
                        }
                    ?></td>                
                </tr>
            <?php
                }
            ?>
        </tbody>
    </table>

</div>

<?php

Template::printFooter();