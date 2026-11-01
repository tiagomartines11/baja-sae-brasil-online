<?php
namespace Baja\Fila;

use Baja\Model\FilaQuery;
use Baja\Model\Senha;
use Baja\Model\SenhaQuery;
use Baja\Model\Evento;
use Baja\Model\EventoQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Model\Equipe;
use Baja\Model\EquipeQuery;

use Baja\Model\Map\SenhaTableMap;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Propel\Runtime\Propel;

use \Datetime;

if (!defined("FECHADA"))     define("FECHADA",0);
if (!defined("ABERTA"))      define("ABERTA",1);
if (!defined("PROG_ABRIR"))  define("PROG_ABRIR",2);
if (!defined("PROG_FECHAR")) define("PROG_FECHAR",3);

if (!defined("FILA"))        define("FILA",0);
if (!defined("ATENDIDO"))    define("ATENDIDO",1);
if (!defined("AGUARDANDO"))  define("AGUARDANDO",2);
if (!defined("NOSHOW"))      define("NOSHOW",3);
if (!defined("ABANDONADO"))  define("ABANDONADO",4);
if (!defined("CANCELADA"))   define("CANCELADA",5);


class Fila
{
    static function checkOpen($evento_id, $fila_id) {
        $fila = FilaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->findOne();
        if ($fila === null) {
            return false;
        } else {
            if ($fila->getStatus() == ABERTA) {
                return true;
            } elseif ($fila->getStatus() == FECHADA) {
                return false;
            } elseif ($fila->getStatus() == PROG_FECHAR) {
                $timestamp_fechar = $fila->getFechamentoProgramado() ;
                $currentDateTime = new DateTime('now');
                if ($timestamp_fechar) {
                    if ($currentDateTime >= $timestamp_fechar) {
                        $fila->setStatus(0);
                        $fila->save();
                        return false;
                    } else {
                        return true;
                    }
                } else {
                    throw new Exception('Fila programada para fechar sem horário de fechamento.');
                }
            } elseif ($fila->getStatus() == PROG_ABRIR) {
                $timestamp_abrir = $fila->getAberturaProgramada();
                $currentDateTime = new DateTime('now');
                if ($timestamp_abrir) {
                    if ($currentDateTime >= $timestamp_abrir) {
                        $timestamp_fechar = $fila->getFechamentoProgramado();
                        if ($timestamp_fechar) {
                            $fila->setStatus(PROG_FECHAR);
                        } else {
                            $fila->setStatus(ABERTA);
                        }
                        $fila->save();
                        return true;
                    } else {
                        return false;
                    }
                } else {
                    throw new Exception('Fila programada para abrir sem horário de abertura.');
                }
            } else {
                throw new Exception('Fila com status implausível.');
            }
        }
    }

    static function getFila($evento_id, $fila_id, $limit=15, $detalhes=false) {

        if ($detalhes) {
            $senhas_fila = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->orderBySenha()->limit($limit)->find();    
        } else {
            $senhas_fila = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->filterByStatus(FILA)->orderBySenha()->limit($limit)->find();
        }

        if (!$senhas_fila) return false;
        
        $results = array();
        
        foreach($senhas_fila as $sf) {
            if ($detalhes) {
                $equipe = EquipeQuery::create()->filterByEventoId($evento_id)->findOneByEquipeId($sf->getEquipeId());
                array_push($results, array("senha"=>$sf->getSenha(), "equipe_id"=>$sf->getEquipeId(), "status"=>$sf->getStatus(), "ts_requisicao"=>$sf->getTsRequisicao(), "ts_status"=>$sf->getTsStatus(), "detalhes"=>$sf->getDetalhes(), "equipe_nome"=>$equipe->getEquipe()));
            } else {
                array_push($results, array("senha"=>$sf->getSenha(), "equipe_id"=>$sf->getEquipeId()));
            }
        }

        return $results;
    }

    static function getFilas(){
        $filas = FilaQuery::create()->filterByStatus(array(PROG_ABRIR, PROG_FECHAR))->find();
        
        foreach($filas as $fp) {
            self::checkOpen($fp->getEventoId(), $fp->getFilaId());
        }

        $filas = FilaQuery::create()->filterByStatus(array(ABERTA, PROG_ABRIR, PROG_FECHAR))->useEventoQuery()->orderByEmAndamento('desc')->orderByAno('desc')->orderByTitulo('asc')->endUse()->find();

        $results = array();

        foreach($filas as $f) {
            $evento_id = $f->getEventoId();
            $evento_nome = EventoQuery::create()->findPK($evento_id)->getTitulo();
            array_push($results, array("evento_id"=>$evento_id, "evento_nome"=>$evento_nome, "fila_id"=> $f->getFilaId(), "nome"=>$f->getNome(), "status"=>$f->getStatus(),"abertura_programada"=>$f->getAberturaProgramada(),"fechamento_programado"=>$f->getFechamentoProgramado()));
        }

        return $results;
    }

    static function getSenhaAtual($evento_id, $fila_id) {
        
        $senha_atual = SenhaQuery::create()->select('min')->addAsColumn('min', 'MIN(senha)-1')->filterByStatus(FILA)->filterByEventoId($evento_id)->filterByFilaId($fila_id)->findOne();

        if ($senha_atual === null) {
            $senha_atual = SenhaQuery::create()->select('max')->addAsColumn('max', 'MAX(senha)')->filterByEventoId($evento_id)->filterByFilaId($fila_id)->findOne();
        }

        if ($senha_atual === null) {
            $senha_atual = 0;
        }

        return $senha_atual;
    }

    static function getCarroSenha($evento_id, $fila_id, $s){
        $senha = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->filterBySenha($s)->findOne();

        if ($senha === null) {
            return false;
        }

        return $senha->getEquipeId();
    }

    /**
     * The queues this user may use, in events not yet finalized, as
     * ['evento_id', 'fila_id', 'permissao']: 'ADMIN' to run the queue, or
     * a team number to take tickets for that team.
     *
     * Two sources since BAJAAPP-76:
     *
     *   ADMIN    fila.manage on the queue (RBAC: queue_manager on it, or a
     *            role above it). Was the code {EVT}_FILA_{n}_ADMIN.
     *   a team   the team's own code {EVT}_FILA_{n}_{team} in
     *            user.permissions. Not a role: it belongs to the team, and
     *            moves with the teams package. Still read here.
     */
    static function getFilasUsuario($usuario) {
        $usuario = UserQuery::create()->findOneByUsername($usuario);

        if ($usuario === null) return false;

        $permissoes_filas = array();

        foreach (FilaQuery::create()->orderByEventoId()->orderByFilaId()->find() as $fila) {
            $evento = EventoQuery::create()->findPk($fila->getEventoId());
            if ($evento === null || $evento->isFinalizado()) continue;
            if (Authorization::can($usuario, Permission::FILA_MANAGE, ScopeNode::fila($fila->getEventoId(), (int) $fila->getFilaId()))) {
                array_push($permissoes_filas, array("evento_id"=>$fila->getEventoId(),"fila_id"=>(string) $fila->getFilaId(),"permissao"=>'ADMIN'));
            }
        }

        foreach(self::codigosDeEquipe($usuario) as $p) {
            $permissao_detalhes = explode('_', $p);

            $evento = EventoQuery::create()->findPk($permissao_detalhes[0]);
            if ($evento !== null && !$evento->isFinalizado()) {
                array_push($permissoes_filas, array("evento_id"=>$permissao_detalhes[0],"fila_id"=>$permissao_detalhes[2],"permissao"=>$permissao_detalhes[3]));
            }
        }

        return $permissoes_filas;
    }

    /**
     * May this user act on the queue: as its manager (fila.manage on it),
     * or for this team (the team's own code)?
     */
    static function checkPermissaoFila($usuario, $evento_id, $fila_id, $equipe_id, $check_admin=false) {
        $usr = UserQuery::create()->findOneByUsername($usuario);

        if (!$usr) return false;

        if (preg_match('/^\d+$/', (string) $fila_id) === 1
            && Authorization::can($usr, Permission::FILA_MANAGE, ScopeNode::fila((string) $evento_id, (int) $fila_id))) {
            return true;
        }

        // Never the old {EVT}_FILA_{n}_ADMIN code, which stays in the column
        // for one release (rollback) but means nothing now: a team called
        // "ADMIN" would read it back.
        if ((string) $equipe_id === 'ADMIN') return false;

        return $usr->hasPermission($evento_id.'_FILA_'.$fila_id.'_'.$equipe_id);
    }

    /**
     * The team queue codes ({EVT}_FILA_{n}_{team}) in user.permissions:
     * the one kind of legacy code still read (see getFilasUsuario).
     *
     * @return string[]
     */
    static function codigosDeEquipe(User $usuario) {
        return array_values(array_filter(
            (array) $usuario->getPermissions(),
            fn($p) => preg_match('/^[^_]+_FILA_[^_]+_(?!ADMIN$)[^_]+$/', (string) $p) === 1
        ));
    }

    static function addPermissaoFila($evento_id, $fila_id, $equipe_id, $novo_usuario) {
        try {
            // An empty username must never reach setUsername() below. The shim
            // represents an anonymous session as username => '', so a row with
            // an empty username would be matched by every unauthenticated
            // visitor in Session::initSession and hand them this permission.
            // initSession refuses to query on '' as well; this is the other
            // half, keeping the row from existing at all.
            if (trim((string) $novo_usuario) === '') {
                return false;
            }

            if (self::checkPermissaoFila($novo_usuario, $evento_id, $fila_id, $equipe_id)) {
                return true;
            } else {
                $usuario_detalhes = UserQuery::create()->findOneByUsername($novo_usuario);

                if (is_null($usuario_detalhes)) {
                    $usuario_novo_detalhes = new User();
                    $usuario_novo_detalhes->setUsername($novo_usuario);
                    $usuario_novo_detalhes->addPermission($evento_id.'_FILA_'.$fila_id.'_'.$equipe_id);
                    $usuario_novo_detalhes->save();
                } else {
                    $usuario_detalhes->addPermission($evento_id.'_FILA_'.$fila_id.'_'.$equipe_id);
                    $usuario_detalhes->save();
                }

                return true;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    static function getSenhasCarro($evento_id, $fila_id, $equipe_id) {
        $senhas = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->filterByEquipeId($equipe_id)->filterByStatus(array(FILA, AGUARDANDO))->find();

        $resultados = array();

        foreach($senhas as $s) {
            array_push($resultados, $s->getSenha());
        }

        return $resultados;
    }

    static function abandonaSenha($evento_id, $fila_id, $senha) {
        $senha_detalhes = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->findOneBySenha($senha);

        if ($senha_detalhes === NULL) return false;
        
        try {
            $equipe_id = $senha_detalhes->getEquipeId();

            if ($senha_detalhes->getStatus() == FILA || $senha_detalhes->getStatus() == AGUARDANDO) {
                $senha_detalhes->setStatus(ABANDONADO);
                $senha_detalhes->save();
            }
            return true;
        
        } catch (Exception $e) {
            return false;
        }        
    }

    static function geraSenha($evento_id, $fila_id, $equipe_id) {
        try {
            $ts_requisicao = floor(microtime(true)*1000);
            $senha = new Senha();
            $senha->setEventoId($evento_id);
            $senha->setFilaId($fila_id);
            $senha->setEquipeId($equipe_id);
            $senha->setTsRequisicao($ts_requisicao);
            $senha->setTsStatus($ts_requisicao);
            $senha->save();
            
            //Not able to get autogenerated column senha with Propel. $senha->update() after save() doesn't work.
            //Workaround using second call to find by timestamp. Changed timestamp to milliseconds to avoid collision.

            $nova_senha = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->findOneByTsRequisicao($ts_requisicao);
            
            return $nova_senha->getSenha();
        } catch (Exception $e) {
            return false;
        }
    }

    static function setStatus($evento_id, $fila_id, $senha, $status) {
        try {
            $ts_status = floor(microtime(true)*1000);
            $senha_detalhes = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->filterBySenha($senha)->findOne();
            
            $senha_detalhes->setStatus($status);
            $senha_detalhes->setTsStatus($ts_status);
            $senha_detalhes->save();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    

    
}