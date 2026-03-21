<?php
namespace Baja\Fila;

use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Model\FilaQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

$operacao = filter_var($_POST['operacao'], FILTER_SANITIZE_STRING);

$usuario = Session::getCurrentUser()->getUsername();

switch($operacao){
    case 'novaSenha':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            $carro = filter_var($_POST['carro'], FILTER_SANITIZE_STRING);
            if (Fila::checkPermissaoFila($usuario, $evento_id, $fila_id, $carro)) {
                $fila = FilaQuery::create()->filterByEventoId($evento_id)->findOneByFilaId($fila_id);

                if (!$fila->isPermiteMultiplas()) {
                    $senhas_atual = Fila::getSenhasCarro($evento_id, $fila_id, $carro);
                    if (sizeof($senhas_atual) == 0) {
                        $nova_senha = Fila::geraSenha($evento_id, $fila_id, $carro);
                    } else {
                        http_response_code(500);
                        die('Proibido múltiplas senhas.');
                    }
                } else {
                    $nova_senha = Fila::geraSenha($evento_id, $fila_id, $carro);
                }

                if ($nova_senha !== false) {
                    http_response_code(201);
                    echo json_encode(array("operacao"=>"novaSenha","evento_id"=>$evento_id,"fila_id"=>$fila_id,"carro"=>$carro,"senha"=>$nova_senha));
                    exit();
                } else {
                    http_response_code(500);
                    die('Server error');
                }

            } else {
                http_response_code(403);
                die('Forbidden');
            }
        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    case 'filaAtual':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            http_response_code(200);
            echo json_encode(array("operacao"=>"filaAtual","evento_id"=>$evento_id,"fila_id"=>$fila_id,"senhaAtual"=>Fila::getSenhaAtual($evento_id, $fila_id)));
            exit();
        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    case 'getSenhasCarro':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            $carro = filter_var($_POST['carro'], FILTER_SANITIZE_STRING);
            
            http_response_code(200);
            echo json_encode(array("operacao"=>"getSenhasCarro","evento_id"=>$evento_id,"fila_id"=>$fila_id,"carro"=>$carro,"senhas"=>Fila::getSenhasCarro($evento_id, $fila_id, $carro)));
            exit();

        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    case 'abandonaSenha':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            $carro = filter_var($_POST['carro'], FILTER_SANITIZE_STRING);
            $senha = filter_var($_POST['senha'], FILTER_SANITIZE_STRING);
            if (Fila::checkPermissaoFila($usuario, $evento_id, $fila_id, $carro)) {
                if (Fila::abandonaSenha($evento_id, $fila_id, $senha) === true) {
                    http_response_code(200);
                    echo json_encode(array("operacao"=>"abandonaSenha","evento_id"=>$evento_id,"fila_id"=>$fila_id,"carro"=>$carro,"senha"=>$senha));
                    exit();
                } else {
                    http_response_code(500);
                    die('Impossível abandonar.');
                }
            } else {
                http_response_code(403);
                die('Forbidden');
            }
        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    
    case 'getSenhas':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            
            http_response_code(200);
            echo json_encode(array("operacao"=>"getSenhas","evento_id"=>$evento_id,"fila_id"=>$fila_id,"senhas"=>Fila::getFila($evento_id, $fila_id, $limit=1000, $detalhes=true)));
            exit();
        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    
    case 'setStatus':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            $senha = filter_var($_POST['senha'], FILTER_SANITIZE_STRING);
            $status = filter_var($_POST['status'], FILTER_SANITIZE_STRING);

            if (Fila::checkPermissaoFila($usuario, $evento_id, $fila_id, $equipe_id, true)) {
                if (Fila::setStatus($evento_id, $fila_id, $senha, $status)) {
                    http_response_code(200);
                    echo json_encode(array("operacao"=>"setStatus","evento_id"=>$evento_id,"fila_id"=>$fila_id,"senha"=>$senha,"status"=>$status));
                    exit();
                } else {
                    http_response_code(500);
                    die('Erro alterando.');
                }
            } else {
                http_response_code(403);
                die('Forbidden');
            }
        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    
    case 'addPermissao':
        try {
            $evento_id = filter_var($_POST['evento_id'], FILTER_SANITIZE_STRING);
            $fila_id = filter_var($_POST['fila_id'], FILTER_SANITIZE_STRING);
            $equipe_id = filter_var($_POST['equipe_id'], FILTER_SANITIZE_STRING);
            $novo_usuario = filter_var($_POST['novo_usuario'], FILTER_SANITIZE_STRING);

            if (Fila::checkPermissaoFila($usuario, $evento_id, $fila_id, $equipe_id, true)) {
                if (Fila::addPermissaoFila($evento_id, $fila_id, $equipe_id, $novo_usuario)) {
                    http_response_code(201);
                    echo json_encode(array("operacao"=>"addPermissao","evento_id"=>$evento_id,"fila_id"=>$fila_id,"equipe_id"=>$equipe_id,"novo_usuario"=>$novo_usuario));
                    exit();
                } else {
                    http_response_code(500);
                    die('Erro adicionando permissão.');
                }
            } else {
                http_response_code(403);
                die('Forbidden');
            }
        } catch (Exception $e) {
            http_response_code(500);
            die($e);
        }
        break;
    
    default:
        http_response_code(501);
        die('Invalid operation');
}