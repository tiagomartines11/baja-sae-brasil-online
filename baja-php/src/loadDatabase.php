<?php
$serviceContainer = \Propel\Runtime\Propel::getServiceContainer();
$serviceContainer->initDatabaseMapFromDumps(array (
  'resultados' => 
  array (
    'tablesByName' => 
    array (
      'equipe' => '\\Baja\\Model\\Map\\EquipeTableMap',
      'evento' => '\\Baja\\Model\\Map\\EventoTableMap',
      'fila' => '\\Baja\\Model\\Map\\FilaTableMap',
      'input' => '\\Baja\\Model\\Map\\InputTableMap',
      'log' => '\\Baja\\Model\\Map\\LogTableMap',
      'participantes' => '\\Baja\\Model\\Map\\ParticipanteTableMap',
      'premiacao' => '\\Baja\\Model\\Map\\PremiacaoTableMap',
      'prova' => '\\Baja\\Model\\Map\\ProvaTableMap',
      'resultado' => '\\Baja\\Model\\Map\\ResultadoTableMap',
      'senha' => '\\Baja\\Model\\Map\\SenhaTableMap',
      'tournament' => '\\Baja\\Model\\Map\\TournamentTableMap',
      'user' => '\\Baja\\Model\\Map\\UserTableMap',
    ),
    'tablesByPhpName' => 
    array (
      '\\Equipe' => '\\Baja\\Model\\Map\\EquipeTableMap',
      '\\Evento' => '\\Baja\\Model\\Map\\EventoTableMap',
      '\\Fila' => '\\Baja\\Model\\Map\\FilaTableMap',
      '\\Input' => '\\Baja\\Model\\Map\\InputTableMap',
      '\\Log' => '\\Baja\\Model\\Map\\LogTableMap',
      '\\Participante' => '\\Baja\\Model\\Map\\ParticipanteTableMap',
      '\\Premiacao' => '\\Baja\\Model\\Map\\PremiacaoTableMap',
      '\\Prova' => '\\Baja\\Model\\Map\\ProvaTableMap',
      '\\Resultado' => '\\Baja\\Model\\Map\\ResultadoTableMap',
      '\\Senha' => '\\Baja\\Model\\Map\\SenhaTableMap',
      '\\Tournament' => '\\Baja\\Model\\Map\\TournamentTableMap',
      '\\User' => '\\Baja\\Model\\Map\\UserTableMap',
    ),
  ),
));
