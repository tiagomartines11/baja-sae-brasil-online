<?php

namespace Baja\Fila;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\SenhaQuery;
use Baja\Model\EquipeQuery;

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

if (!isset($_REQUEST['evento']) || !isset($_REQUEST['fila']) || !isset($_REQUEST['carro'])) {
    header("Location: filapicker.php");
}

$evento_id = $_REQUEST['evento'];
$fila_id = $_REQUEST['fila'];
$carro = $_REQUEST['carro'];

$filas_usuario = Fila::getFilasUsuario(Session::getCurrentUser()->getUsername());

$permissao = false;

foreach($filas_usuario as $fu) {
    if ($fu['evento_id'] == $evento_id && $fu['fila_id'] == $fila_id && $fu['permissao'] == $carro) {
        $permissao = true;
    }
}

if (!$permissao) {
    header("Location: filapicker.php");
}

try {
    $fila = FilaQuery::create()->filterByEventoId($evento_id)->filterByFilaId((int)$fila_id)->findOne();
} catch (Exception $e) {
    die ('Dados inválidos.');
}

$evento = EventoQuery::create()->findPk($evento_id);

$equipe = EquipeQuery::create()->filterByEventoId($evento_id)->filterByEquipeId($carro)->findOne();

Template::printHeader('Fila '.$fila->getNome());

?>

        <base target="_top">
        <div class="container">
            <h5 class="center-align" style="font-weight:bold;"><?= $evento->getNome() ?></h5>
            <div class="divider"></div>
            <h5 class="center-align" style="font-style:italic">Fila <?= $fila->getNome() ?></h5>
            <div class="section">
                <div class="row">
                    <div class="card red accent-2">
                        <div class="card-content white-text center-align">
                            <span class="card-title">Senha atual da prova:</span>
                            <span id="senhaAtual" style="font-size:8rem;font-weight:bold"><?= Fila::getSenhaAtual($evento_id, $fila_id) ?></span>
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
            </div>
            <div class="divider"></div>
<?php if ($fila->getStatus() == FECHADA || $fila->getStatus() == PROG_ABRIR) { ?>
            <div class="section">
                <div class="card blue darken-2" id="s-">
                    <div class="card-content white-text center-align">
                        <span style="font-size:6rem;font-weight:bold">Fila fechada</span>
                    </div>        
                </div>
            </div>
<?php } else { ?>
            <div class="section">
                <h5 class="center-align" style="font-style:italic">Minhas senhas</h5>
                <div class="row" id="senhasSection">
          
                </div>
                <div class="card blue lighten-1">
                    <div class="card-action">
                        <div class="preloader-wrapper small active hide" id="spinner-atualiza">
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
                        <span id="ok-atualiza" class="hide">✅</span>
                        <span id="error-atualiza" class="hide">❌</span>
                        <a class="white-text" href="javascript:void(0);" id="link-atualiza" onclick="updateSenhas()">Atualizar minhas senhas</a>
                    </div>
                </div>
    <?php if($fila->isPermiteMultiplas()) { ?>
                <div class="card orange lighten-2">
                    <div class="card-action">
                        <div class="preloader-wrapper small active hide" id="spinner-nova-perm">
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
                        <a class="white-text" href="javascript:void(0);" id="link-nova-perm" onclick="solicitaSenha()">Solicitar senha</a>
                    </div>
                </div>
    <?php } ?>      
            </div>
<?php } ?>
            <div class="divider"></div>
            
            <div class="section">
                <div class="row">
                    <div class="col card small green lighten-1 s12 m5">
                        <div class="card-content white-text center-align">
                            <span class="card-title">Carro:</span>
                            <span id="minhaSenha" style="font-size:8rem;font-weight:bold"><?= $carro ?></span>
                        </div>
                    </div>
                    <div class="col card small s12 m6 push-m1 valign-wrapper">
                        <div class="card-content left-align">
                            <h4><b>Equipe:</b> <?= $equipe->getEquipe() ?></h4>
                            <h5><b>Escola:</b> <?= $equipe->getEscola() ?></h5>
                        </div>
                    </div>
                </div>
            </div>

            <div id="modalAbandona" class="modal modal-fixed-footer">
                <div class="modal-content">
                    <h4>Abandonar fila</h4>
                    <p>Tem certeza que deseja abandonar a fila sem solicitar uma nova senha? Essa operação não pode ser desfeita.</p>
                </div>
                <div class="modal-footer">
                    <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="confirmaAbandonaSenha(this)">SIM</a>
                    <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="endMudancaSenha(this)">NÃO</a>
                </div>
            </div>

            <div id="modalTroca" class="modal modal-fixed-footer">
                <div class="modal-content">
                    <h4>Trocar senha</h4>
                    <p>Tem certeza que deseja abandonar essa senha a solicitar uma nova senha no final da fila? Essa operação não pode ser desfeita.</p>
                </div>
                <div class="modal-footer">
                    <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="confirmaTrocaSenha(this)">SIM</a>
                    <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="endMudancaSenha(this)">NÃO</a>
                </div>
            </div>

        <div id="modalNovaSenha" class="modal modal-fixed-footer">
            <div class="modal-content">
                <h4>Nova senha</h4>
                <p>Tem certeza que deseja solicitar uma nova senha?</p>
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="confirmaSolicitaSenha()">SIM</a>
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat" onclick="endNovaSenha()">NÃO</a>
            </div>
            </div>
    
        <div id="modalErro" class="modal modal-fixed-footer">
            <div class="modal-content">
                <h4>ERRO!</h4>
                <p>Erro ao alterar a senha <span id="senhaErro" style="font-weight:bold;">XX</span>. Por favor, tente novamente.</p>
                <p>Erro ao solicitar nova senha. Por favor, tente novamente.</p>
            </div>
            <div class="modal-footer">
                <a href="javascript:void(0);" class="modal-close waves-effect waves-green btn-flat">OK</a>
            </div>
        </div>
    

        <template id="templateSenha">
            <div class="card blue darken-2" id="s-">
                <div class="card-content white-text center-align">
                    <span id="minhaSenha" style="font-size:8rem;font-weight:bold">X</span>
                </div>
                <div class="card-action">
                    <div class="preloader-wrapper small active hide" id="spinner-">
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
                    <a class="white-text" id="s-ab-" href="javascript:void(0);" onclick="abandonaSenha(this)">Abandonar</a>
                    <a class="white-text" id="s-tr-" href="javascript:void(0);" onclick="trocaSenha(this)">Trocar</a>
                </div>
            </div>
        </template>

        <template id="templateNovaSenha">
            <div class="card blue darken-2" id="s-">
                <div class="card-content white-text center-align">
                    <span id="minhaSenha" style="font-size:8rem;font-weight:bold">--</span>
                </div>
                <div class="card-action">
                    <div class="preloader-wrapper small active hide" id="spinner-nova">
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
                    <a class="white-text" href="javascript:void(0);" id="link-nova" onclick="solicitaSenha()">Solicitar Senha</a>
                </div>
            </div>
        </template>

        
        <script type="text/javascript">


            let senhas=<?= json_encode(Fila::getSenhasCarro($evento_id, $fila_id, $carro)) ?>;

            const evento_id='<?= $evento_id ?>';
            const fila_id=<?= $fila_id ?>;
            const carro=<?= $carro ?>;

            document.addEventListener('DOMContentLoaded', function() {
                var elems = document.querySelectorAll('.modal');
                var instances = M.Modal.init(elems,{dismissible: false});

                populateSenhas();
            });

            //ATUALIZA LISTA

            function populateSenhas() {
                let senhasSection = document.getElementById("senhasSection");
                senhasSection.innerHTML = '';
                if (senhas.length == 0) {
                    let template = document.getElementById("templateNovaSenha").content.cloneNode(true);
                    senhasSection.appendChild(template);
                } else {
                    senhas.forEach((s)=>{
                        let novaSenha = document.getElementById("templateSenha").content.cloneNode(true);
                        novaSenha.children[0].id='s-'+s;
                        novaSenha.children[0].children[1].children[0].id='spinner-'+s;
                        novaSenha.children[0].children[1].children[1].id='s-ab-'+s;
                        novaSenha.children[0].children[1].children[2].id='s-tr-'+s;
                        novaSenha.children[0].children[0].children[0].innerHTML=s;

                        senhasSection.appendChild(novaSenha);
                    });
                }
            }

            function showAtualizando() {
                document.getElementById("spinner-atualiza").classList.remove('hide');
                document.getElementById("link-atualiza").classList.add('hide');
                document.getElementById("ok-atualiza").classList.add('hide');
                document.getElementById("error-atualiza").classList.add('hide');
            }

            function delayAtualizando() {
                document.getElementById("ok-atualiza").classList.remove('hide');
                document.getElementById("spinner-atualiza").classList.add('hide');
                document.getElementById("error-atualiza").classList.add('hide');
                document.getElementById("link-atualiza").classList.add('hide');
                setTimeout(hideAtualizando,10000);
            }

            function hideAtualizando() {
                document.getElementById("spinner-atualiza").classList.add('hide');        
                document.getElementById("link-atualiza").classList.remove('hide');
                document.getElementById("ok-atualiza").classList.add('hide');
                document.getElementById("error-atualiza").classList.add('hide');
            }

            function errorAtualizando() {
                document.getElementById("spinner-atualiza").classList.add('hide');        
                document.getElementById("link-atualiza").classList.add('hide');
                document.getElementById("ok-atualiza").classList.add('hide');
                document.getElementById("error-atualiza").classList.remove('hide');        
                setTimeout(hideAtualizando,2000);
            }

            function startMudancaSenha(senha) {
                document.getElementById("spinner-"+senha).classList.remove('hide');
                document.getElementById("s-ab-"+senha).classList.add('hide');
                document.getElementById("s-tr-"+senha).classList.add('hide');
            }

            function updateSenhas() {
                showAtualizando();
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 200) {
                        if (xhr.response.operacao=='getSenhasCarro' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id && xhr.response.carro==carro) { 
                            callbackUpdateSenhas(xhr.response.senhas);
                        } else {
                            console.log(1);
                            errorAtualizando();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 200) {
                        errorAtualizando();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=getSenhasCarro&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id)+'&carro='+encodeURIComponent(carro));
            }

            function callbackUpdateSenhas(fila) {
                if (fila !== false) {
                    senhas = fila;
                    populateSenhas();
                    delayAtualizando();
                } else {
                    errorAtualizando();
                }
            }

            //ABANDONA

            function abandonaSenha(el) {
                let senhaAlterar = parseInt(el.id.replace(/\D/g,""));
                startMudancaSenha(senhaAlterar);
                let modalElement = document.getElementById("modalAbandona");
                modalElement.children[1].children[0].id='s-ab-cf-'+senhaAlterar
                modalElement.children[1].children[1].id='s-ab-ncf-'+senhaAlterar
                M.Modal.getInstance(modalElement).open()
            }

            function confirmaAbandonaSenha(el) {
                let senha = 0;
                if (Number.isInteger(el)) {
                    senha = el;
                } else {
                    senha = parseInt(el.id.replace(/\D/g,""));
                }
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 200) {
                        if (xhr.response.operacao=='abandonaSenha' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id && xhr.response.carro==carro && xhr.response.senha==senha) {
                            callbackAbandonaSenha(senha);
                        } else {
                            endMudancaSenha(senha)
                            errorAtualizando();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 200) {
                        endMudancaSenha(senha)
                        errorAtualizando();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=abandonaSenha&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id)+'&carro='+encodeURIComponent(carro)+'&senha='+encodeURIComponent(senha));
            }

            function callbackAbandonaSenha(s) {
                document.getElementById("s-"+s).remove();
                senhas = senhas.filter(e => e !== s);
                senhas.sort((a,b) => a-b);
                populateSenhas();
            }            

            function endMudancaSenha(senha) {
                let s=senha;
                if (!Number.isInteger(senha)) {
                s=parseInt(senha.id.replace(/\D/g,""));
                }
                document.getElementById("spinner-"+s).classList.add('hide');
                document.getElementById("s-ab-"+s).classList.remove('hide');
                document.getElementById("s-tr-"+s).classList.remove('hide');
            }

            function showErrorMudanca(s) {
                let modalElement = document.getElementById("modalErro");
                modalElement.children[0].children[1].children[0].innerHTML = s;
                modalElement.children[0].children[1].classList.remove('hide');
                modalElement.children[0].children[2].classList.add('hide');
                M.Modal.getInstance(modalElement).open()
            }

            //TROCA

            function trocaSenha(el) {
                let senhaAlterar = parseInt(el.id.replace(/\D/g,""));
                startMudancaSenha(senhaAlterar);
                let modalElement = document.getElementById("modalTroca");
                modalElement.children[1].children[0].id='s-tr-cf-'+senhaAlterar
                modalElement.children[1].children[1].id='s-tr-ncf-'+senhaAlterar
                M.Modal.getInstance(modalElement).open()
            }

            function confirmaTrocaSenha(el) {
                let senha = parseInt(el.id.replace(/\D/g,""));
                confirmaAbandonaSenha(senha);
                setTimeout(() => {
                    confirmaSolicitaSenha();    
                }, 2000);
                
            }

            function callbackTrocaSenha(s, s2=false) {
                if(!Number.isInteger(s)){
                console.log(JSON.stringify(s));
                endMudancaSenha(s[1]);
                showErrorMudanca(s[1]);
                } else {
                updateSenhas();
                }
            }

            //NOVA SENHA

            function solicitaSenha(){
                try {
                document.getElementById("spinner-nova").classList.remove('hide');
                document.getElementById("link-nova").classList.add('hide');
                } catch (e) {}
                try {
                document.getElementById("spinner-nova-perm").classList.remove('hide');
                document.getElementById("link-nova-perm").classList.add('hide');
                } catch (e) {}
                let modalElement = document.getElementById("modalNovaSenha");        
                M.Modal.getInstance(modalElement).open();
            }

            function confirmaSolicitaSenha(){
                let xhr = new XMLHttpRequest();
                xhr.onreadystatechange = function() {
                    if (this.readyState === XMLHttpRequest.DONE && this.status == 201) {
                        if (xhr.response.operacao=='novaSenha' && xhr.response.evento_id==evento_id && xhr.response.fila_id==fila_id && xhr.response.carro==carro) {                                
                            callbackNovaSenha(xhr.response.senha);
                        } else {
                            console.log(1);
                            erroNovaSenha();    
                        }
                    } else if (this.readyState === XMLHttpRequest.DONE && this.status != 201) {
                        erroNovaSenha();
                    }
                };
                xhr.open("POST", "filaaction.php?", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.responseType = 'json';
                xhr.send('operacao=novaSenha&evento_id='+encodeURIComponent(evento_id)+'&fila_id='+encodeURIComponent(fila_id)+'&carro='+encodeURIComponent(carro));
            }

            function callbackNovaSenha(s) {
                if(!Number.isInteger(s)){
                    console.log(JSON.stringify(s));
                    erroNovaSenha();
                } else {
                    senhas.push(s)
                    senhas.sort((a,b) => a-b);
                    try {
                        document.getElementById("spinner-nova").classList.add('hide');
                        document.getElementById("link-nova").classList.remove('hide');
                    } catch (e) {}
                    try {
                        document.getElementById("spinner-nova-perm").classList.add('hide');
                        document.getElementById("link-nova-perm").classList.remove('hide');
                    } catch (e) {}
                    populateSenhas();
                }
            }

            function erroNovaSenha() {
                endNovaSenha();
                showErrorNova();                
            }

            function endNovaSenha() {
                try {
                document.getElementById("spinner-nova").classList.add('hide');
                document.getElementById("link-nova").classList.remove('hide');
                } catch (e) {}
                try {
                document.getElementById("spinner-nova-perm").classList.add('hide');
                document.getElementById("link-nova-perm").classList.remove('hide');
                } catch (e) {}
            }

            function showErrorNova() {
                let modalElement = document.getElementById("modalErro");
                modalElement.children[0].children[1].classList.add('hide');
                modalElement.children[0].children[2].classList.remove('hide');
                M.Modal.getInstance(modalElement).open()
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
                            console.log(1);
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

        </script>
    </body>
</html>
