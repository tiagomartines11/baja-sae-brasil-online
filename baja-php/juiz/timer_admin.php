<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\TimerQuery;
use Baja\Model\Timer;
use Baja\Session;
use Baja\Url;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['nova'])) { header("Location: admin_timers.php"); exit; }

$_page = (int)@$_REQUEST['id'];

Session::permissionCheck('TIMER_ADMIN');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$username = Session::getCurrentUser()->getUsername();

$nova = false;

if (isset($_REQUEST['nova']) && $_REQUEST['nova'] == 'true') {
    $nova = true;

    $ultimo = TimerQuery::create()->filterByEventoId($currentEventId)->orderByTimerId('desc')->findOne();
    $proximoId = $ultimo ? $ultimo->getTimerId() + 1 : 1;

    if (@$_REQUEST['act'] == 'Salvar') {
        $config = Timer::normalizeConfig(json_decode(@$_POST['config']));
        $timerId = (int)@$_POST['timer_id'];
        if (!$config || $timerId < 1) {
            header("Location: timer_admin.php?nova=true&erro=1"); exit;
        } else {
            $exists = TimerQuery::create()->filterByEventoId($currentEventId)->findOneByTimerId($timerId);
            if ($exists) { header("Location: timer_admin.php?id=" . $timerId); exit; }

            $timer = new Timer();
            $timer->setEventoId($currentEventId);
            $timer->setTimerId($timerId);
            $timer->setConfig($config);
            $timer->generateAccessKey();
            $timer->appendLog($username, 'criado');
            $timer->save();

            header("Location: admin_timers.php"); exit;
        }
    }

} else {

    $timer = TimerQuery::create()->filterByEventoId($currentEventId)->findOneByTimerId($_page);
    if (!$timer) { header("Location: admin_timers.php"); exit; }

    $backups = @json_decode($timer->getConfigBackup(), true);
    if (!$backups) $backups = array();

    $bkNomeados = array();
    if (count($backups) > 0) {
        foreach ($backups as $key => $bk) {
            if (!in_array($key, array('-1', '-2', '-3', '-4', '-5'))) {
                $bkNomeados[$key] = $bk;
            }
        }
    }

    if (@$_REQUEST['act'] == '❌') {
        if (isset($_POST['nomeado']) && $_POST['nomeado'] != '' && isset($bkNomeados[$_POST['nomeado']])) {
            unset($backups[$_POST['nomeado']]);
            $timer->setConfigBackup(json_encode($backups));
            $timer->save();
        }
        header("Location: timer_admin.php?id=" . $_page); exit;
    }

    if (@$_REQUEST['act'] == '💾') {
        $config = Timer::normalizeConfig(json_decode(@$_POST['config']));
        if ($config && isset($_POST['novoNomeadoNome']) && $_POST['novoNomeadoNome'] != '') {
            $backups[$_POST['novoNomeadoNome']] = $config;
            $timer->setConfigBackup(json_encode($backups));
            $timer->save();
        }
        header("Location: timer_admin.php?id=" . $_page); exit;
    }

    $bk1 = isset($backups['-1']) ? $backups['-1'] : null;
    $bk2 = isset($backups['-2']) ? $backups['-2'] : null;
    $bk3 = isset($backups['-3']) ? $backups['-3'] : null;
    $bk4 = isset($backups['-4']) ? $backups['-4'] : null;
    $bk5 = isset($backups['-5']) ? $backups['-5'] : null;

    if (@$_REQUEST['act'] == 'Atualizar') {
        $current = $timer->getConfig();
        $incoming = Timer::normalizeConfig(json_decode(@$_POST['config']));
        if (!$incoming) { header("Location: timer_admin.php?id=" . $_page . "&erro=1"); exit; }

        // Compared as arrays: MySQL hands the JSON back with its keys reordered.
        if (Timer::normalizeConfig($current) != $incoming) {
            $backups['-5'] = $bk4;
            $backups['-4'] = $bk3;
            $backups['-3'] = $bk2;
            $backups['-2'] = $bk1;
            $backups['-1'] = $current;
            $timer->setConfigBackup(json_encode($backups));
            $timer->setConfig($incoming);
            // A run in progress was counting against the old sequence; devices pick this up and stop.
            $timer->setState(['status' => 'stopped', 'step' => 0, 'started_at' => 0, 'remaining_ms' => 0, 'at' => (int)round(microtime(true) * 1000)]);
            $timer->appendLog($username, 'config');
            $timer->save();
        }

        header("Location: timer_admin.php?id=" . $_page); exit;
    }

    if (@$_REQUEST['act'] == 'Gerar Nova Chave') {
        $timer->generateAccessKey();
        $timer->appendLog($username, 'nova_chave');
        $timer->save();
        header("Location: timer_admin.php?id=" . $_page); exit;
    }

    if (@$_REQUEST['act'] == 'Deletar Timer') {
        $timer->delete();
        header("Location: admin_timers.php"); exit;
    }
}

$sons = Timer::availableSounds();
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

Template::printHeader("Timer", false);

?>

<style>
    /* Not a table: Template applies tablesorter to every table on the page. */
    #etapas > div { display: flex; align-items: center; gap: 4px; margin: 3px 0; white-space: nowrap; }
    #etapas .num { width: 22px; text-align: right; }
    #etapas input[type=number] { width: 48px; }
    #etapas input[type=text] { width: 180px; }
    #etapas select { width: 150px; }
    .erro { color: #b00; font-weight: bold; }
</style>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($nova) { ?>
    <form action="timer_admin.php?nova=true" method="POST">
<?php } else { ?>
    <form action="timer_admin.php?id=<?= $timer->getTimerId() ?>" method="POST">
<?php } ?>
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_timers.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $nova ? 'Novo Timer' : htmlspecialchars($timer->getNome()) ?></span>
                    </th>
                </tr>
            </thead>
            <tbody>
<?php if (isset($_REQUEST['erro'])) { ?>
                <tr>
                    <td colspan="2" class="erro">Configuração inválida: é preciso um ID, e ao menos uma etapa com nome e tempo.</td>
                </tr>
<?php } ?>
                <tr>
                    <td>Evento</td>
                    <td><input style="width:700px;" type="text" name="evento_id" disabled value="<?= htmlspecialchars($currentEventId) ?>"/></td>
                </tr>
                <tr>
                    <td>ID Timer</td>
                    <td><input style="width:700px;" type="number" min="1" step="1" name="timer_id" <?= $nova ? '' : 'disabled' ?> value="<?= $nova ? $proximoId : $timer->getTimerId() ?>" /></td>
                </tr>
<?php if (!$nova) { ?>
                <tr>
                    <td>Acesso sem login</td>
                    <td>
                        Chave: <code style="font-size: 18px;"><?= htmlspecialchars($timer->getAccessKey()) ?></code>
                        &nbsp;&nbsp;
                        <a href="<?= htmlspecialchars(Url::subdomain('juiz', '/timer.php?k=' . $timer->getAccessKey())) ?>" target="_blank"><?= htmlspecialchars(Url::subdomain('juiz', '/timer.php?k=' . $timer->getAccessKey())) ?></a>
                        &nbsp;&nbsp;
                        <input type="submit" name="act" value="Gerar Nova Chave" onclick="return confirm('A chave atual deixa de funcionar. Continuar?');"/>
                    </td>
                </tr>
                <tr>
                    <td>Backups</td>
                    <td>
                        <button type="button" class="bkBotao bkSelected" id="bkAtual" onclick="restoreAtual()">Configuração Atual</button><br/><br/>

                        <strong>Backups Últimas Alterações:</strong>
                        <button type="button" class="bkBotao" id="bk-1" onclick="loadNumbered('-1')" <?= (!$bk1) ? 'disabled' : '' ?>>-1</button>
                        <button type="button" class="bkBotao" id="bk-2" onclick="loadNumbered('-2')" <?= (!$bk2) ? 'disabled' : '' ?>>-2</button>
                        <button type="button" class="bkBotao" id="bk-3" onclick="loadNumbered('-3')" <?= (!$bk3) ? 'disabled' : '' ?>>-3</button>
                        <button type="button" class="bkBotao" id="bk-4" onclick="loadNumbered('-4')" <?= (!$bk4) ? 'disabled' : '' ?>>-4</button>
                        <button type="button" class="bkBotao" id="bk-5" onclick="loadNumbered('-5')" <?= (!$bk5) ? 'disabled' : '' ?>>-5</button><br/><br/>

                        <strong>Backups Nomeados:</strong>
                        <select name="nomeado" id="nomeado" onchange="loadNamed()">
                            <option disabled selected value> -- selecione -- </option>
                            <?php foreach ($bkNomeados as $k => $bk) { ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                            <?php } ?>
                        </select>
                        <input type="submit" name="act" value="❌"/>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        <strong>Salvar Backup com Nome:</strong>
                        <input type="text" name="novoNomeadoNome" id="novoNomeadoNome" oninput="checkNovoNomeado()"/>
                        <input type="submit" name="act" value="💾" id="salvaNovoNomeado" disabled/>
                    </td>
                </tr>
<?php } ?>
                <tr>
                    <td>Nome</td>
                    <td><input style="width:700px;" type="text" id="cfgNome" /></td>
                </tr>
                <tr>
                    <td>Ao terminar</td>
                    <td style="text-align: left;"><label><input type="checkbox" id="cfgLoop"> Voltar para a primeira etapa e continuar</label></td>
                </tr>
                <tr>
                    <td>Etapas</td>
                    <td>
                        <div id="etapas"></div>
                        <br/>
                        <input type="button" value="Adicionar Etapa" onclick="addEtapa()"/>
                        &nbsp;&nbsp; Duração total: <strong id="total"></strong>
                    </td>
                </tr>
<?php if (getenv('TTS_URL')) { ?>
                <tr>
                    <td>Novo som<br/>(texto para fala)</td>
                    <td style="text-align: left;">
                        <input type="text" id="ttsTexto" maxlength="300" style="width:380px;" onkeydown="if (event.key == 'Enter') { event.preventDefault(); gerarSom(); }" placeholder="Frase a ser falada, ex.: Faltam dois minutos"/>
                        <input type="text" id="ttsNome" maxlength="40" style="width:150px;" placeholder="Nome (opcional)"/>
                        <select id="ttsRitmo" title="Velocidade da fala">
                            <option value="1">Normal</option>
                            <option value="1.25" selected>Devagar</option>
                            <option value="1.5">Mais devagar</option>
                            <option value="1.8">Bem devagar</option>
                        </select>
                        <input type="button" value="Gerar Som" onclick="gerarSom()"/>
                        <span id="ttsMsg"></span>
                    </td>
                </tr>
<?php } ?>
                <tr>
                    <td>JSON</td>
                    <td>
                        <details>
                            <summary>Editar como JSON</summary>
                            <textarea style="width:700px;min-height:300px;" name="config" id="config" oninput="fromJson()"></textarea>
                            <div id="jsonErro" class="erro"></div>
                        </details>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <?php if (!$nova) { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" /></th>
                </tr>
                <tr>
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Timer" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar este timer? Essa operação não pode ser desfeita.</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Timer" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
                        </div>
                    </th>
                </tr>
                <?php } else { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Salvar" /></th>
                </tr>
                <?php } ?>
            </tfoot>
        </table>
    </form>

<?php if (!$nova) { ?>
    <br/>
    <table class="tablesorter">
        <thead>
            <tr><th colspan="4" class="sorter-false">Log (últimos 50 registros)</th></tr>
            <tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Etapa</th></tr>
        </thead>
        <tbody>
<?php foreach (array_reverse(array_slice($timer->getLog(), -50)) as $l) { ?>
            <tr>
                <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($l['t']))) ?></td>
                <td><?= htmlspecialchars($l['quem']) ?></td>
                <td><?= htmlspecialchars($l['acao']) ?></td>
                <td><?= htmlspecialchars($l['etapa'] ?? '') ?></td>
            </tr>
<?php } ?>
        </tbody>
    </table>
<?php } ?>

    <script type="text/javascript">
        const SONS = <?= json_encode($sons, $jsonFlags) ?>;
        const atual = <?= $nova ? 'null' : json_encode($timer->getConfig(), $jsonFlags) ?>;
        const backs = <?= (!$nova && $timer->getConfigBackup()) ? json_encode(json_decode($timer->getConfigBackup()), $jsonFlags) : '{}' ?>;

        const configEl = document.getElementById("config");
        const selectEl = document.getElementById("nomeado");
        const etapasEl = document.getElementById("etapas");

        const novoNomeadoNome = document.getElementById("novoNomeadoNome");
        const novoNomeadoBtn = document.getElementById("salvaNovoNomeado");

        // The form fields are a view over cfg; the textarea is what gets posted.
        let cfg = atual || { nome: "", loop: true, etapas: [{ nome: "", tempo: 60, som_inicio: null, som_fim: null }] };

        function somSelect(valor, onchange) {
            const s = document.createElement("select");
            s.add(new Option("— nenhum —", ""));
            SONS.forEach((f) => s.add(new Option(f, f)));
            s.value = valor || "";
            s.onchange = () => onchange(s.value || null);
            return s;
        }

        function ouvir(arquivo) {
            new Audio("sons/" + arquivo.split("/").map(encodeURIComponent).join("/") + "?" + Date.now()).play();
        }

        // Asks the server to speak a sentence into a new sound file, which then shows up in every sound list.
        function gerarSom(substituir) {
            const texto = document.getElementById("ttsTexto").value.trim();
            const msg = document.getElementById("ttsMsg");
            if (!texto) return;
            const dados = new URLSearchParams({ texto: texto, nome: document.getElementById("ttsNome").value, ritmo: document.getElementById("ttsRitmo").value });
            if (substituir) dados.set("substituir", "1");
            msg.className = ""; msg.textContent = "Gerando...";
            fetch("timer_tts.php", { method: "POST", body: dados, credentials: "same-origin" })
                .then((r) => r.json().then((d) => ({ status: r.status, d: d })))
                .then(({ status, d }) => {
                    if (status == 409 && confirm(d.erro + " Substituir?")) return gerarSom(true);
                    if (!d.ok) throw d.erro;
                    if (SONS.indexOf(d.arquivo) < 0) { SONS.push(d.arquivo); SONS.sort(); }
                    render();
                    msg.textContent = "Criado: " + d.arquivo;
                    ouvir(d.arquivo);
                })
                .catch((e) => { msg.className = "erro"; msg.textContent = typeof e == "string" ? e : "Falha ao gerar o som."; });
        }

        function botao(texto, titulo, onclick, disabled) {
            const b = document.createElement("input");
            b.type = "button"; b.value = texto; b.title = titulo; b.onclick = onclick; b.disabled = !!disabled;
            return b;
        }

        function render() {
            document.getElementById("cfgNome").value = cfg.nome || "";
            document.getElementById("cfgLoop").checked = !!cfg.loop;

            etapasEl.innerHTML = "";
            cfg.etapas.forEach((e, i) => {
                const row = document.createElement("div");

                const num = document.createElement("span");
                num.className = "num"; num.textContent = i + 1;

                const nome = document.createElement("input");
                nome.type = "text"; nome.placeholder = "Nome da etapa"; nome.value = e.nome || "";
                nome.oninput = () => { e.nome = nome.value; toJson(); };

                const min = document.createElement("input"), seg = document.createElement("input");
                min.type = seg.type = "number"; min.min = seg.min = 0; seg.max = 59;
                min.title = "Minutos"; seg.title = "Segundos";
                min.value = Math.floor((e.tempo || 0) / 60); seg.value = (e.tempo || 0) % 60;
                min.oninput = seg.oninput = () => { e.tempo = (parseInt(min.value) || 0) * 60 + (parseInt(seg.value) || 0); toJson(); };

                row.append(num, nome, min, "min", seg, "s");

                [["som_inicio", "início"], ["som_fim", "fim"]].forEach(([campo, rotulo]) => {
                    row.append(
                        " Som " + rotulo + ":",
                        somSelect(e[campo], (v) => { e[campo] = v; toJson(); }),
                        botao("▶", "Ouvir", () => { if (e[campo]) ouvir(e[campo]); })
                    );
                });

                row.append(
                    botao("↑", "Mover para cima", () => moveEtapa(i, -1), i == 0),
                    botao("↓", "Mover para baixo", () => moveEtapa(i, 1), i == cfg.etapas.length - 1),
                    botao("⧉", "Duplicar", () => { cfg.etapas.splice(i + 1, 0, Object.assign({}, e)); render(); toJson(); }),
                    botao("✕", "Remover", () => { cfg.etapas.splice(i, 1); render(); toJson(); }, cfg.etapas.length == 1)
                );
                etapasEl.append(row);
            });
            renderTotal();
        }

        function renderTotal() {
            const t = cfg.etapas.reduce((a, e) => a + (e.tempo || 0), 0);
            document.getElementById("total").textContent = Math.floor(t / 60) + " min " + (t % 60) + " s";
        }

        function toJson() {
            configEl.value = JSON.stringify(cfg, null, 2);
            document.getElementById("jsonErro").textContent = "";
            renderTotal();
            resetStyles();
        }

        function fromJson() {
            try {
                const c = JSON.parse(configEl.value);
                if (!c || !Array.isArray(c.etapas)) throw 0;
                cfg = c;
                render();
                document.getElementById("jsonErro").textContent = "";
            } catch (e) {
                document.getElementById("jsonErro").textContent = "JSON inválido — os campos acima não foram atualizados.";
            }
            resetStyles();
        }

        function load(c) {
            cfg = JSON.parse(JSON.stringify(c));
            render();
            toJson();
        }

        function addEtapa() {
            cfg.etapas.push({ nome: "", tempo: 60, som_inicio: null, som_fim: null });
            render(); toJson();
        }

        function moveEtapa(i, d) {
            cfg.etapas.splice(i + d, 0, cfg.etapas.splice(i, 1)[0]);
            render(); toJson();
        }

        document.getElementById("cfgNome").oninput = (ev) => { cfg.nome = ev.target.value; toJson(); };
        document.getElementById("cfgLoop").onchange = (ev) => { cfg.loop = ev.target.checked; toJson(); };

        function resetStyles() {
            let els = document.querySelectorAll(".bkBotao");
            els.forEach((e) => { e.classList.remove('bkSelected'); });
            if (selectEl) selectEl.classList.remove('bkSelected');
        }

        function loadNumbered(i) {
            if (backs[i]) {
                load(backs[i]);
                document.getElementById('bk' + i).classList.add('bkSelected');
            }
        }

        function loadNamed() {
            if (backs[selectEl.value]) {
                load(backs[selectEl.value]);
                selectEl.classList.add('bkSelected');
            }
        }

        function restoreAtual() {
            load(atual);
            document.getElementById("bkAtual").classList.add('bkSelected');
        }

        function checkNovoNomeado() {
            if (novoNomeadoNome.value != '' && !backs[novoNomeadoNome.value]) {
                novoNomeadoBtn.disabled = false;
            } else {
                novoNomeadoBtn.disabled = true;
            }
        }

        function confirmDelete() {
            document.getElementById("deleteBtn").style.display = 'none';
            document.getElementById("deleteConfirmBtn").style.display = '';
        }

        function cancelDelete() {
            document.getElementById("deleteBtn").style.display = '';
            document.getElementById("deleteConfirmBtn").style.display = 'none';
        }

        render();
        configEl.value = JSON.stringify(cfg, null, 2);
    </script>

</div>

<?php
Template::printFooter();
