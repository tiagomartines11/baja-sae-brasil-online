<?php
$_ENV = getenv('ENV') ?: 'dev';
require_once(__DIR__ . "/../vendor/autoload.php");
require_once(__DIR__ . "/config." . $_ENV . ".php");

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once(__DIR__ . "/phpbb_login.php");

$_DEV_MODE = $user->data["username"] == "Tiago" || $user->data["username"] == "jbresolin";

date_default_timezone_set('America/Sao_Paulo');

use Baja\Model\EventoQuery;

// Lógica para seleção de evento se não houver request de um eveno específico (substituição ao setup manual em .htaccess)
if (empty($_SERVER['REDIRECT_EVENT'])) {
    try {
        // Tenatativa 1: Evento marcado como "em andamento"
        $event = EventoQuery::create()
            ->filterByEmAndamento(true)
            ->orderByAno('desc')
            ->orderByTipo('asc')
            ->findOne();

        // Tenatativa 2: Baja Nacional (tipo=0) com maior ano
        if (!$event) {
            $event = EventoQuery::create()
                ->filterByTipo(0)
                ->orderByAno('desc')
                ->findOne();
        }

        // Fallback: primeiro nacional com registro
        if ($event) {
            $_SERVER['REDIRECT_EVENT'] = $event->getEventoId();
        } else {
            $_SERVER['REDIRECT_EVENT'] = '11BR';
        }

    } catch (Exception $e) {
        // Fallback para catástrofes
        $_SERVER['REDIRECT_EVENT'] = '11BR';
    }
}