<?php

namespace Baja\Fila;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\SenhaQuery;
use Baja\Model\EquipeQuery;
use Baja\Site\OneSignalClient;

use Baja\Session;

define("FECHADA",0);
define("ABERTA",1);
define("PROG_ABRIR",2);
define("PROG_FECHAR",3);

define("FILA",0);
define("ATENDIDO",1);
define("AGUARDANDO",2);
define("NOSHOW",3);
define("ABANDONADO",4);
define("CANCELADA",5);

if (!isset($_REQUEST['evento']) || !isset($_REQUEST['fila'])) {
    header("Location: filapicker.php");
}

$evento_id = $_REQUEST['evento'];
$fila_id = $_REQUEST['fila'];

$filas_usuario = Fila::getFilasUsuario(Session::getCurrentUser()->getUsername());

$permissao = false;

foreach($filas_usuario as $fu) {
    if ($fu['evento_id'] == $evento_id && $fu['fila_id'] == $fila_id && $fu['permissao'] == 'ADMIN') {
        $permissao = true;
    }
}

if (!$permissao) {
    header("Location: filapicker.php");
}

// Handle push notification requests
if (@$_REQUEST['act'] == 'push') {
    header('Content-Type: application/json');
    $response = OneSignalClient::sendMessage(@$_POST['heading'], @$_POST['msg'], "/", @$_POST['filter']);
    echo json_encode(['success' => true, 'response' => $response]);
    exit;
}

try {
    $fila = FilaQuery::create()->filterByEventoId($evento_id)->filterByFilaId((int)$fila_id)->findOne();
} catch (\Exception $e) {
    die ('Dados inválidos.');
}

Template::printHeader('Fila '.$fila->getNome());

?>

        <base target="_top">
    
        <div class="container">
            <h5 class="center-align" style="font-weight:bold;"><?= EventoQuery::create()->findPk($evento_id)->getTitulo() ?></h5>
            <div class="divider"></div>
            <h5 class="center-align" style="font-style:italic">Fila <?= $fila->getNome() ?></h5>
            <div class="section">
                <div class="row">
                    <div class="card red accent-2 col s12">
                        <div class="card-content white-text center-align">
                            <span class="card-title" style="font-size:1.5rem">Senha atual da prova:</span>
                            <span id="senhaAtual" style="font-size:6rem;font-weight:bold"><?= Fila::getSenhaAtual($evento_id, $fila_id) ?></span>              
                        </div>
                        <div class="card-action">
                            <div class="preloader-wrapper small active hide" id="spinner-fila">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>            
                            <span id="ok-fila" class="hide">✅</span>
                            <span id="error-fila" class="hide">❌</span>
                            <a class="white-text" href="javascript:void(0);" id="link-fila" onclick="updateFila()">Atualizar</a>
                        </div>
                    </div>
                </div>
                <div class="row" id="esperaSection">
            
                </div>
                <div class="row" id="proximoSection">
                    <div class="card green darken-1 col s12 m5" onclick="atendeProximo()">
                        <div class="card-content white-text center-align">
                            <div class="preloader-wrapper small active hide" id="spinner-at-prox">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>
                            <span style="font-size:3rem;font-weight:bold" id="link-at-prox">Atender próximo</span>              
                        </div>            
                    </div>
                    <div class="card amber darken-2 col s12 m5 push-m2" onclick="esperaProximo()">
                        <div class="card-content white-text center-align">
                            <div class="preloader-wrapper small active hide" id="spinner-esp-prox">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>
                            <span style="font-size:3rem;font-weight:bold" id="link-esp-prox">Espera próximo</span>              
                        </div>            
                    </div>
                </div>
                <div class="row">
                    <div class="card teal lighten-5 col s12 center-align">
                        <div class="card-content white-text center-align">
                            <div class="switch">
                                <label>
                                    Completa
                                    <input type="checkbox" onclick="toggleCompleta()" checked>
                                    <span class="lever"></span>
                                    Abertas
                                </label>
                            </div>
                            
                            <br/>
                            
                            <div class="preloader-wrapper small active hide" id="spinner-atualiza-servidor">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>
                            <span id="ok-atualiza-servidor" class="hide">✅</span>
                            <span id="error-atualiza-servidor" class="hide">❌</span>
                            <a id="link-atualiza-servidor" href="javascript:void(0);" class="waves-effect waves-light btn" style="margin:5px;" onclick="updateSenhas()">Atualizar com servidor</a>
                            
                            <br/>
                            
                            <div class="preloader-wrapper small active hide" id="spinner-cria-senha">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>
                            <span id="ok-cria-senha" class="hide">✅</span>
                            <span id="error-cria-senha" class="hide">❌</span>
                            <a id="link-cria-senha" href="javascript:void(0);" class="waves-effect waves-light btn" style="margin:5px;" onclick="criaSenha()">Cria Senha</a>
                            
                            <br/>

                            <div class="preloader-wrapper small active hide" id="spinner-cria-permissao">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>
                            <span id="ok-cria-permissao" class="hide">✅</span>
                            <span id="error-cria-permissao" class="hide">❌</span>
                            <a id="link-cria-permissao" href="javascript:void(0);" class="waves-effect waves-light btn" style="margin:5px;" onclick="criaPermissao()">Adicionar permissão de usuário</a>
                            
                            <br/>
                            
                            <div class="preloader-wrapper small active hide" id="spinner-notifica-5">
                                <div class="spinner-layer spinner-red-only">
                                    <div class="circle-clipper left">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="gap-patch">
                                        <div class="circle"></div>
                                    </div>
                                    <div class="circle-clipper right">
                                        <div class="circle"></div>
                                    </div>
                                </div>
                            </div>
                            <span id="ok-notifica-5" class="hide">✅</span>
                            <span id="error-notifica-5" class="hide">❌</span>
                            <a id="link-notifica-5" href="javascript:void(0);" class="waves-effect waves-light btn" style="margin:5px;" onclick="comandoNotifica5();">Notificar Próximos 5</a><br/>
                            <a id="link-confirma-notifica-5" href="javascript:void(0);" class="waves-effect waves-light btn hide" style="margin:5px;background-color:red;" onclick="confirmaNotifica5();">Tem certeza?</a><br/>              
                        </div>            
                    </div>
                </div>
            </div>
            <div class="divider"></div>
            <div class="section">
                <div class="row">
                    <table class="striped highlight centered">
                        <thead>
                            <th>Carro</th>
                            <th>Equipe</th>
                            <th>Senha</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </thead>
                        <tbody id="filaSection">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="divider"></div>        
        </div></div>
    

        <template id="templateSenha">
            <tr id="s-">
                <td></td>
                <td></td>
                <td style="font-weight:bold;"></td>
                <td></td>
                <td id="s-a-">
                    <div class="preloader-wrapper small active hide">
                        <div class="spinner-layer spinner-green-only">
                            <div class="circle-clipper left">
                                <div class="circle"></div>
                            </div>
                            <div class="gap-patch">
                                <div class="circle"></div>
                            </div>
                            <div class="circle-clipper right">
                                <div class="circle"></div>
                            </div>
                        </div>
                    </div>
                    <a href="javascript:void(0);" onclick="acoes(this.parentNode)"><i class="material-icons">edit_square</i></a>
                </td>
            </tr>
        </template>

        <template id="templateEspera">
            <div class="card pink darken-4 col s12">
                <div id="e-" class="card-content white-text center-align">
                    <span class="card-title" style="font-size:1.2rem">Em espera:</span>
                    <span style="font-size:3.5rem;font-weight:bold">XXX</span>
                    <h5>XXX</h5>
                    Aguardando até <span>XX:XX:XX</span><br>
                    <h2 id="e-timer-">XX:XX</h2>
                    <div class="preloader-wrapper small active hide" id="e-spinner-">
                        <div class="spinner-layer spinner-green-only">
                            <div class="circle-clipper left">
                                <div class="circle"></div>
                            </div>
                            <div class="gap-patch">
                                <div class="circle"></div>
                            </div>
                            <div class="circle-clipper right">
                                <div class="circle"></div>
                            </div>
                        </div>
                    </div>
                    <a href="javascript:void(0);" id="e-link-" class="waves-effect waves-light btn" style="margin:5px;" onclick="chegouFila(this)">Chegou</a><br/>
                </div>
            </div>
        </template>

        <div id="modalAcao" class="modal modal-fixed-footer" style="top:5% !important;max-height:100% !important;height:100% !important;">
            <div class="modal-content">
                <h4 style="font-weight:bold;">Senha: XXX</h4>
                <h5>Carro: XXX</h5>
                <h5>Equipe: XXX</h5>
                <br/>
                <p>Selecione uma ação:</p>
                <a href="javascript:void(0);" class="waves-effect waves-light btn-large" style="margin:15px;" onclick="setStatus(parseInt(this.parentNode.id.replace(/\D/g,'')),1);"><i class="material-icons">done_all</i>&nbsp;&nbsp;Atendido</a><br/>
                <a href="javascript:void(0);" class="waves-effect waves-light btn-large" style="margin:15px;" onclick="setStatus(parseInt(this.parentNode.id.replace(/\D/g,'')),2);"><i class="material-icons">timer</i>&nbsp;&nbsp;Iniciar espera</a><br/>
                <a href="javascript:void(0);" class="waves-effect waves-light btn-large" style="margin:15px;" onclick="setStatus(parseInt(this.parentNode.id.replace(/\D/g,'')),3);"><i class="material-icons">search_off</i>&nbsp;&nbsp;No-Show</a><br/><br/><br/>
                <a href="javascript:void(0);" class="waves-effect waves-light btn-large" style="margin:15px;background-color:red;" onclick="setStatus(parseInt(this.parentNode.id.replace(/\D/g,'')),0);"><i class="material-icons">menu</i>&nbsp;&nbsp;Fila</a><br/>
                <a href="javascript:void(0);" class="waves-effect waves-light btn-large" style="margin:15px;background-color:red;" onclick="setStatus(parseInt(this.parentNode.id.replace(/\D/g,'')),4);"><i class="material-icons">cancel</i>&nbsp;&nbsp;Abandonado</a><br/>
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="toggleVisibilidadeSenha(parseInt(this.parentNode.parentNode.children[0].id.replace(/\D/g,'')),true)">FECHAR</a>
            </div>
        </div>

        <div id="modalAtendeProximo" class="modal modal-fixed-footer" style="top:15% !important;max-height:70% !important;height:70% !important;">
            <div class="modal-content">
                <h2 style="font-weight:bold;">Senha: XXX</h2>
                <h5>Carro: XXX</h5>
                <h5>Equipe: XXX</h5>
                <br/>
                <h3>Tem certeza que deseja marcar essa senha como atendida?</h3>            
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="confirmaAtendeProximo();">SIM</a>
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">NÃO</a>
            </div>
        </div>

        <div id="modalEsperaProximo" class="modal modal-fixed-footer" style="top:15% !important;max-height:70% !important;height:70% !important;">
            <div class="modal-content">
                <h2 style="font-weight:bold;">Senha: XXX</h2>
                <h5>Carro: XXX</h5>
                <h5>Equipe: XXX</h5>
                <br/>
                <h3>Tem certeza que deseja iniciar o período de espera dessa senha?</h3>
                
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="confirmaEsperaProximo();">SIM</a>
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">NÃO</a>
            </div>
        </div>

        <div id="modalErroProximo" class="modal modal-fixed-footer" style="top:15% !important;max-height:70% !important;height:70% !important;">
            <div class="modal-content">
                <h3>Não é possível atender a próxima senha até que o fim do período de espera da senha atual.</h3>
                <h3>Por favor, aguarde.</h3>        
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">OK</a>
            </div>
        </div>

        <div id="modalErrosetStatus" class="modal modal-fixed-footer" style="top:15% !important;max-height:70% !important;height:70% !important;">
            <div class="modal-content">
                <h3>Não foi possível processar a soliciação.</h3>
                <h3>Por favor, tente novamente.</h3>
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">OK</a>
            </div>
        </div>

        <div id="modalNovaSenha" class="modal modal-fixed-footer" style="top:15% !important;max-height:100% !important;height:50% !important;">
            <div class="modal-content">
                <h3>Solicitar nova senha.</h3>
                <div class="row">
                <form class="col s12">
                    <div class="input-field">
                    <input placeholder="Número equipe" id="novaSenhaEquipe" type="number" min="1" max="1000" class="validate">
                        <label for="novaSenhaEquipe">Equipe</label>
                    </div>
                </form>
                </div>
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="solicitaNovaSenha()">SOLICITAR</a>
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">FECHAR</a>
            </div>
        </div>

        <div id="modalNovaPermissao" class="modal modal-fixed-footer" style="top:15% !important;max-height:100% !important;height:50% !important;">
            <div class="modal-content">
                <h3>Adicionar permissao ao usuário.</h3>
                <div class="row">
                <form class="col s12">
                    <div class="input-field">
                        <input placeholder="Número equipe" id="novaPermissaoEquipe" type="number" min="1" max="1000" class="validate">
                        <label for="novaPermissaoEquipe">Equipe</label>
                    </div>
                    <div class="input-field">
                        <input placeholder="usuario" id="novaPermissaoUsuario" type="text">
                        <label for="novaPermissaoUsuario">Usuario</label>
                    </div>
                </form>
                </div>
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="confirmaCriaPermissao()">ADICIONAR</a>
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">FECHAR</a>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
        
        <script type="text/javascript">

            let fila = <?= json_encode(Fila::getFila($evento_id, $fila_id, $limit=1000, $detalhes=true)) ?>;
            
            const evento_id='<?= $evento_id ?>';
            const fila_id=<?= $fila_id ?>;
            
            let full = false;
            let tempo_chamada = <?= $fila->getTempoEspera() ?>;
            let permite_chamada_espera = <?= $fila->isPermiteChamadaEspera()==1?'true':'false' ?>;
            let proximo = 0;
            let proxDisable = false;

            let filaEspera = [];

            let proximos5 = [];
            let proximos5old = [];

            let firstRun = true;
            
            document.addEventListener('DOMContentLoaded', function() {
                var elems = document.querySelectorAll('.modal');
                var instances = M.Modal.init(elems,{dismissible: false, endingTop:0.05});

                populateSenhas();
            });

            //ATUALIZA LISTA

            function updateSenhas() {
                showAtualizandoSenhas();
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 200) {
                        if (xhr.response.operacao=='getSenhas' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id) { 
                            callbackUpdateSenhas(xhr.response.senhas);
                        } else {
                            console.log(1);
                            errorAtualizandoSenhas();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 200) {
                        errorAtualizandoSenhas();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=getSenhas&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id));
            }

            function callbackUpdateSenhas(f) {
                fila = f;
                if (fila===false) {
                errorAtualizandoSenhas();
                } else {
                delayAtualizandoSenhas();
                }
                populateSenhas();
            }

            function showAtualizandoSenhas() {
                document.getElementById("spinner-atualiza-servidor").classList.remove('hide');
                document.getElementById("link-atualiza-servidor").classList.add('hide');
                document.getElementById("ok-atualiza-servidor").classList.add('hide');
                document.getElementById("error-atualiza-servidor").classList.add('hide');
            }

            function delayAtualizandoSenhas() {
                document.getElementById("spinner-atualiza-servidor").classList.add('hide');
                document.getElementById("link-atualiza-servidor").classList.add('hide');
                document.getElementById("ok-atualiza-servidor").classList.remove('hide');
                document.getElementById("error-atualiza-servidor").classList.add('hide');
                setTimeout(hideAtualizandoSenhas,5000);
            }

            function hideAtualizandoSenhas() {
                document.getElementById("spinner-atualiza-servidor").classList.add('hide');
                document.getElementById("link-atualiza-servidor").classList.remove('hide');
                document.getElementById("ok-atualiza-servidor").classList.add('hide');
                document.getElementById("error-atualiza-servidor").classList.add('hide');
            }

            function errorAtualizandoSenhas() {
                document.getElementById("spinner-atualiza-servidor").classList.add('hide');
                document.getElementById("link-atualiza-servidor").classList.add('hide');
                document.getElementById("ok-atualiza-servidor").classList.add('hide');
                document.getElementById("error-atualiza-servidor").classList.remove('hide');
                setTimeout(hideAtualizandoFila,2000);
            }

            function populateSenhas() {
                sortFila();
                //0: equipe_id, 1: equipe_nome, 2: ts_requisição, 3: senha, 4: status, 5:ts_status
                let filaSection = document.getElementById("filaSection");
                filaSection.innerHTML = '';
                let esperaSection = document.getElementById("esperaSection");
                esperaSection.innerHTML = '';

                const texto_status = ['Fila', 'Atendido', 'Aguardando', 'No Show', 'Abandonado', 'Cancelada'];
                
                filaEspera = [];
                proximos5 = [];
                proximo = 0;
                
                fila.forEach((s)=>{
                if (full || s.status==<?= FILA ?> || s.status==<?= AGUARDANDO ?>) {
                    if (proximo == 0 && s.status==<?= FILA ?>) {
                    proximo = s.senha;
                    }
                    if (proximos5.length<5 && s.status==<?= FILA ?>){
                    proximos5.push(s.senha);
                    } 
                    let novaSenha = document.getElementById("templateSenha").content.cloneNode(true);
                    novaSenha.children[0].id='s-'+s.senha;
                    novaSenha.children[0].children[0].innerHTML=s.equipe_id;
                    novaSenha.children[0].children[1].innerHTML=s.equipe_nome;
                    novaSenha.children[0].children[2].innerHTML=s.senha;
                    novaSenha.children[0].children[3].innerHTML=texto_status[s.status];
                    novaSenha.children[0].children[4].id='s-a-'+s.senha;            
                    filaSection.appendChild(novaSenha);
                }

                if (s.status==<?= AGUARDANDO ?>) {
                    filaEspera.push({"senha": s.senha, "ts_status": s.ts_status, "equipe_id": s.equipe_id, "equipe_nome": s.equipe_nome})
                }
                });

                if (filaEspera.length > 0){
                updateEspera();
                }

                if (firstRun) {
                proximos5old = proximos5;
                firstRun = false;
                }

                if (!proximos5.every((e,i)=>e === proximos5old[i])) {
                proximos5old = proximos5;
                notificaProximos5();
                }
                
            }

            function updateEspera(){
                let esperaSection = document.getElementById("esperaSection");        

                filaEspera.forEach(s=>{
                let novaEspera = document.getElementById("templateEspera").content.cloneNode(true);
                novaEspera.children[0].children[0].id="e-"+s.senha;
                novaEspera.children[0].children[0].children[1].innerHTML = s.senha;
                novaEspera.children[0].children[0].children[2].innerHTML = s.equipe_id + ' - '+s.equipe_nome;
                
                let a = new Date(parseInt(s.ts_status) + parseInt(<?= $fila->getTempoEspera()*1000 ?>));
                novaEspera.children[0].children[0].children[3].innerHTML = (a.getHours().toString().padStart(2,'0')+':'+a.getMinutes().toString().padStart(2,'0')+':'+a.getSeconds().toString().padStart(2,'0'));
                novaEspera.children[0].children[0].children[5].id="e-timer-"+s.senha;
                novaEspera.children[0].children[0].children[6].id="e-spinner-"+s.senha;
                novaEspera.children[0].children[0].children[7].id="e-link-"+s.senha;

                esperaSection.appendChild(novaEspera);
                });

                timerEspera();        
            }

            function timerEspera(){
                let timer = setInterval(function(){
                let now = new Date().getTime();

                filaEspera.forEach((s,i,a)=>{
                    let distance = parseInt(s.ts_status) + parseInt(<?= $fila->getTempoEspera()*1000 ?>) - now;

                    let mins = Math.floor(distance / 60000);
                    let secs = Math.floor((distance % 60000)/1000);

                    let timerElement = document.getElementById("e-timer-"+s.senha)
                    
                    timerElement.innerHTML = mins.toString().padStart(2,'0') + ':' + secs.toString().padStart(2,'0');

                    if (distance <= 0) {
                    filaEspera.splice(i,1);
                    document.getElementById("e-spinner-"+s.senha).classList.remove('hide');
                    document.getElementById("e-link-"+s.senha).classList.add('hide');
                    timerElement.classList.add('hide');
                    setStatus(s.senha,3);
                    }
                })

                if (filaEspera.length == 0) {
                    clearInterval(timer);
                }
                },1000);
            }

            function sortFila() {
                fila.sort((a,b) => a[3]-b[3]);
            }

            function toggleCompleta() {
                full = !full;
                populateSenhas();
            }

            //AÇÕES

            function criaPermissao(){
                let modalElement = document.getElementById("modalNovaPermissao");
                document.getElementById("novaPermissaoEquipe").value='';
                document.getElementById("novaPermissaoUsuario").value='';
                M.Modal.getInstance(modalElement).open();
            }

            function confirmaCriaPermissao(){
                showAtualizandoNovaPermissao();
                
                let equipe_id = document.getElementById("novaPermissaoEquipe").value;
                let novo_usuario = document.getElementById("novaPermissaoUsuario").value;
                
                let xhr = new XMLHttpRequest();
                //////////////////////////////
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 201) {
                        if (xhr.response.operacao=='addPermissao' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id && xhr.response.equipe_id==equipe_id && xhr.response.novo_usuario==novo_usuario) { 
                            successNovaPermissao();
                        } else {
                            errorNovaPermissao();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 201) {
                        errorNovaPermissao();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=addPermissao&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id)+'&equipe_id='+encodeURIComponent(equipe_id)+'&novo_usuario='+encodeURIComponent(novo_usuario));
            }

            function showAtualizandoNovaPermissao(){
                document.getElementById('spinner-cria-permissao').classList.remove('hide');
                document.getElementById('ok-cria-permissao').classList.add('hide');
                document.getElementById('error-cria-permissao').classList.add('hide');
                document.getElementById('link-cria-permissao').classList.add('hide');
            }

            function successNovaPermissao(e=true) {
                if (e!==false) {
                document.getElementById('spinner-cria-permissao').classList.add('hide');
                document.getElementById('ok-cria-permissao').classList.remove('hide');
                document.getElementById('error-cria-permissao').classList.add('hide');
                document.getElementById('link-cria-permissao').classList.add('hide');
                setTimeout(hideNovaPermissao,2000);
                } else {
                errorNovaPermissao();
                }
            }

            function errorNovaPermissao() {
                document.getElementById('spinner-cria-permissao').classList.add('hide');
                document.getElementById('ok-cria-permissao').classList.add('hide');
                document.getElementById('error-cria-permissao').classList.remove('hide');
                document.getElementById('link-cria-permissao').classList.add('hide');
                setTimeout(hideNovaPermissao,2000);
                updateSenhas();
            }

            function hideNovaPermissao() {
                document.getElementById('spinner-cria-permissao').classList.add('hide');
                document.getElementById('ok-cria-permissao').classList.add('hide');
                document.getElementById('error-cria-permissao').classList.add('hide');
                document.getElementById('link-cria-permissao').classList.remove('hide');
            }

            function criaSenha(){
                let modalElement = document.getElementById("modalNovaSenha");
                document.getElementById("novaSenhaEquipe").value='';
                M.Modal.getInstance(modalElement).open();
            }
            
            function solicitaNovaSenha() {
                showAtualizandoNovaSenha();
                
                let carro = document.getElementById("novaSenhaEquipe").value;
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 201) {
                        if (xhr.response.operacao=='novaSenha' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id && xhr.response.carro==carro) { 
                            console.log(xhr.response.senha);
                            successNovaSenha();
                        } else {
                            console.log(1);
                            errorAtualizandoFila();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 201) {
                        errorAtualizandoFila();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=novaSenha&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id)+'&carro='+encodeURIComponent(carro));
            }

            function showAtualizandoNovaSenha(){
                document.getElementById('spinner-cria-senha').classList.remove('hide');
                document.getElementById('ok-cria-senha').classList.add('hide');
                document.getElementById('error-cria-senha').classList.add('hide');
                document.getElementById('link-cria-senha').classList.add('hide');
            }

            function successNovaSenha(e=true) {
                if (e!==false) {
                document.getElementById('spinner-cria-senha').classList.add('hide');
                document.getElementById('ok-cria-senha').classList.remove('hide');
                document.getElementById('error-cria-senha').classList.add('hide');
                document.getElementById('link-cria-senha').classList.add('hide');
                setTimeout(hideNovaSenha,2000);
                } else {
                errorNovaSenha();
                }
                updateSenhas();
            }

            function errorNovaSenha() {
                document.getElementById('spinner-cria-senha').classList.add('hide');
                document.getElementById('ok-cria-senha').classList.add('hide');
                document.getElementById('error-cria-senha').classList.remove('hide');
                document.getElementById('link-cria-senha').classList.add('hide');
                setTimeout(hideNovaSenha,2000);
                updateSenhas();
            }

            function hideNovaSenha() {
                document.getElementById('spinner-cria-senha').classList.add('hide');
                document.getElementById('ok-cria-senha').classList.add('hide');
                document.getElementById('error-cria-senha').classList.add('hide');
                document.getElementById('link-cria-senha').classList.remove('hide');
            }

            function chegouFila(el) {
                let senha=parseInt(el.parentNode.id.replace(/\D/g,''));        
                document.getElementById('e-spinner-'+senha).classList.remove('hide');
                document.getElementById('e-link-'+senha).classList.add('hide');
                setStatus(senha,1);
            }

            function atendeProximo(){
                //0: equipe_id, 1: equipe_nome, 2: ts_requisição, 3: senha, 4: status, 5:ts_status
                if (!proxDisable) {
                let detalhes = fila[fila.findIndex(e=>e.senha==proximo)];

                if (permite_chamada_espera || filaEspera.length == 0){
                    let modalElement = document.getElementById("modalAtendeProximo");
                    modalElement.children[0].children[0].innerHTML = 'Senha: '+detalhes.senha;
                    modalElement.children[0].children[1].innerHTML = 'Carro: '+detalhes.equipe_id;
                    modalElement.children[0].children[2].innerHTML = 'Equipe: '+detalhes.equipe_nome;
                    M.Modal.getInstance(modalElement).open();
                } else {
                    let modalElement = document.getElementById("modalErroProximo");
                    M.Modal.getInstance(modalElement).open();
                }
                }
            }

            function confirmaAtendeProximo(){
                showWorkingProximo();
                setStatus(proximo,1);
            }

            function esperaProximo(){
                if (!proxDisable) {
                let detalhes = fila[fila.findIndex(e=>e.senha==proximo)];
                
                if (permite_chamada_espera || filaEspera.length == 0){
                    let modalElement = document.getElementById("modalEsperaProximo");
                    modalElement.children[0].children[0].innerHTML = 'Senha: '+detalhes.senha;
                    modalElement.children[0].children[1].innerHTML = 'Carro: '+detalhes.equipe_id;
                    modalElement.children[0].children[2].innerHTML = 'Equipe: '+detalhes.equipe_nome;
                    M.Modal.getInstance(modalElement).open();
                } else {
                    let modalElement = document.getElementById("modalErroProximo");
                    M.Modal.getInstance(modalElement).open();
                }
                }
            }

            function confirmaEsperaProximo(){
                showWorkingProximo();
                setStatus(proximo,2);
            }

            function showWorkingProximo(){
                document.getElementById("spinner-at-prox").classList.remove('hide');
                document.getElementById("link-at-prox").classList.add('hide');
                document.getElementById("spinner-esp-prox").classList.remove('hide');
                document.getElementById("link-esp-prox").classList.add('hide');
                proxDisable = true;
            }

            function hideWorkingProximo(){
                document.getElementById("spinner-at-prox").classList.add('hide');
                document.getElementById("link-at-prox").classList.remove('hide');
                document.getElementById("spinner-esp-prox").classList.add('hide');
                document.getElementById("link-esp-prox").classList.remove('hide');
                proxDisable = false;
            }
            
            function acoes(el) {
                let senhaAlterar = parseInt(el.id.replace(/\D/g,""));

                toggleVisibilidadeSenha(senhaAlterar,false);

                let modalElement = document.getElementById("modalAcao");
                modalElement.children[0].id='acao-'+senhaAlterar;
                modalElement.children[0].children[0].innerHTML = "Senha: "+senhaAlterar;
                modalElement.children[0].children[1].innerHTML = "Carro: "+document.getElementById("s-"+senhaAlterar).children[0].innerHTML;
                modalElement.children[0].children[2].innerHTML = "Equipe: "+document.getElementById("s-"+senhaAlterar).children[1].innerHTML;
                M.Modal.getInstance(modalElement).open();
            }

            function toggleVisibilidadeSenha(senha,visivel){
                if (visivel === true) {
                document.getElementById('s-a-'+senha).children[0].classList.add('hide');
                document.getElementById('s-a-'+senha).children[1].classList.remove('hide');
                } else {
                document.getElementById('s-a-'+senha).children[0].classList.remove('hide');
                document.getElementById('s-a-'+senha).children[1].classList.add('hide');
                }
                
            }

            function setStatus(senha, status){
                let modalElement = document.getElementById("modalAcao");
                M.Modal.getInstance(modalElement).close();
                
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 200) {
                        if (xhr.response.operacao=='setStatus' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id && xhr.response.senha==senha && xhr.response.status==status) {
                            callbacksetStatus(xhr.response.senha);
                        } else {
                            errorsetStatus();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 200) {
                        errorsetStatus();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=setStatus&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id)+'&senha='+encodeURIComponent(senha)+'&status='+encodeURIComponent(status));
            }

            function callbacksetStatus(s) {
                if (s===false) {
                errorsetStatus();
                } else {
                let senhaAtualizada = JSON.parse(s);
                hideWorkingProximo();
                updateSenha(s);
                }
                updateSenhas();
            }

            function errorsetStatus() {
                let modalElement = document.getElementById("modalErrosetStatus");
                M.Modal.getInstance(modalElement).open();
                populateSenhas();
                hideWorkingProximo();
                updateSenhas();
                updateFila();
            }

            function getStatusAtual(s) {
                return fila[fila.findIndex(e=>e[3]==s)][4]
            }
            
            function updateSenha(s) {
                //0: equipe_id, 1: equipe_nome, 2: ts_requisição, 3: senha, 4: status, 5:ts_status
                s = JSON.parse(s);
                let index = fila.findIndex((e)=>e[3]==s.senha);
                fila[index][4] = s.status;
                fila[index][5] = s.ts_status;

                if (s.status=='Aguardando') {
                notificaEspera(s.senha,fila[index][0],s.ts_status);
                }

                populateSenhas();
                updateFila();
            }

            //ATUALIZA FILA

            function updateFila() {
                showAtualizandoFila();
                
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 200) {
                        if (xhr.response.operacao=='filaAtual' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id) {
                            callbackUpdateFila(xhr.response.senhaAtual);
                        } else {
                            errorAtualizandoFila();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 200) {
                        errorAtualizandoFila();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=filaAtual&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id));

            }

            function callbackUpdateFila(fila) {
                if (fila !== false && Number.isInteger(fila)) {
                document.getElementById("senhaAtual").innerHTML = fila;
                delayAtualizandoFila();
                } else {
                errorAtualizandoFila();
                }
            }

            function showAtualizandoFila() {
                document.getElementById("spinner-fila").classList.remove('hide');
                document.getElementById("link-fila").classList.add('hide');
                document.getElementById("ok-fila").classList.add('hide');
                document.getElementById("error-fila").classList.add('hide');
            }

            function delayAtualizandoFila() {
                document.getElementById("ok-fila").classList.remove('hide');
                document.getElementById("spinner-fila").classList.add('hide');
                document.getElementById("error-fila").classList.add('hide');
                document.getElementById("link-fila").classList.add('hide');
                setTimeout(hideAtualizandoFila,10000);
            }

            function hideAtualizandoFila() {
                document.getElementById("spinner-fila").classList.add('hide');        
                document.getElementById("link-fila").classList.remove('hide');
                document.getElementById("ok-fila").classList.add('hide');
                document.getElementById("error-fila").classList.add('hide');
            }

            function errorAtualizandoFila() {
                document.getElementById("spinner-fila").classList.add('hide');        
                document.getElementById("link-fila").classList.add('hide');
                document.getElementById("ok-fila").classList.add('hide');
                document.getElementById("error-fila").classList.remove('hide');        
                setTimeout(hideAtualizandoFila,2000);
            }

            //NOTIFICAÇÕES

            function notificaEspera(senha,equipe,ts){
                let titulo = '⚠️ Cadê você?';
                let deadline = new Date(parseInt(ts)+parseInt(<?= $fila->getTempoEspera()*1000 ?>));
                let finalTime = deadline.getHours().toString().padStart(2,'0')+':'+deadline.getMinutes().toString().padStart(2,'0')+':'+deadline.getSeconds().toString().padStart(2,'0')
                let mensagem = 'Sua senha '+senha.toString()+' foi chamada, você têm até as '+finalTime+' para se apresentar a prova.';
                enviaNotificacao(equipe,titulo,mensagem);        
            }

            function notificaProximos5(){
                proximos5.forEach((e,i)=>{
                //0: equipe_id, 1: equipe_nome, 2: ts_requisição, 3: senha, 4: status, 5:ts_status
                let detalhes = fila[fila.findIndex(f=>f.senha==e)];
                enviaNotificacao(detalhes.equipe_id,'🏁 Sua vez está chegando!','Sua senha '+e+' é a '+(i+1)+'ª da fila de ICTS, dirija-se para a entrada da prova.');
                })
            }

            function enviaNotificacao(equipe,titulo,msg) {
                let url='filaadmin.php?evento=<?= $evento_id ?>&fila=<?= $fila_id ?>&act=push';
                let xhr = new XMLHttpRequest();

                let params = 'heading='+encodeURIComponent(titulo)+'&msg='+encodeURIComponent(msg)+'&filter='+equipe.toString()+'&submit=Enviar';

                console.log('Sending notification:', {equipe, titulo, msg, url, params});

                xhr.open('POST', url, true);

                xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

                xhr.onreadystatechange = function() {
                    if(xhr.readyState == 4) {
                        console.log('Notification response:', xhr.status, xhr.responseText);
                        if (xhr.status != 200) {
                            console.error('Notification failed:', xhr.status, xhr.responseText);
                        }
                    }
                }

                xhr.send(params);
            }

            let timeoutNotifica5 = false;

            function comandoNotifica5() {
                document.getElementById('spinner-notifica-5').classList.add('hide');
                document.getElementById('ok-notifica-5').classList.add('hide');
                document.getElementById('error-notifica-5').classList.add('hide');
                document.getElementById('link-notifica-5').classList.add('hide');
                document.getElementById('link-confirma-notifica-5').classList.remove('hide');
                timeoutNotifica5 = setTimeout(hideNotificando5,2000);
            }

            function hideNotificando5(){
                document.getElementById('spinner-notifica-5').classList.add('hide');
                document.getElementById('ok-notifica-5').classList.add('hide');
                document.getElementById('error-notifica-5').classList.add('hide');
                document.getElementById('link-notifica-5').classList.remove('hide');
                document.getElementById('link-confirma-notifica-5').classList.add('hide');
            }

            function delayNotificando5(){
                document.getElementById('spinner-notifica-5').classList.add('hide');
                document.getElementById('ok-notifica-5').classList.remove('hide');
                document.getElementById('error-notifica-5').classList.add('hide');
                document.getElementById('link-notifica-5').classList.add('hide');
                document.getElementById('link-confirma-notifica-5').classList.add('hide');
                setTimeout(hideNotificando5,2000);
            }
            
            function confirmaNotifica5() {
                clearTimeout(timeoutNotifica5);
                notificaProximos5();
                delayNotificando5();
            }

        </script>
