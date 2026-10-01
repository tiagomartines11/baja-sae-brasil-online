<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\TimerQuery;
use Baja\Session;

// Usage page: runs a timer, never edits it. Reachable with a session (pick
// from the timers the user may use) or, without login, with a timer's access key.

$usuario = Session::getCurrentUserOrNull();
list($timer, $quem) = TimerAcesso::resolver();

$h = fn($s) => htmlspecialchars((string)$s);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

$disponiveis = [];
$podeConfigurar = false;
if (!$timer && $usuario) {
    $eventoId = EventoQuery::getCurrentEvent()->getEventoId();
    $podeConfigurar = TimerAcesso::podeConfigurar($eventoId, $usuario);
    foreach (TimerQuery::create()->filterByEventoId($eventoId)->orderByTimerId()->find() as $t) {
        if (TimerAcesso::podeUsar($t, $usuario)) $disponiveis[] = $t;
    }
}

$porChave = isset($_REQUEST['k']) && $_REQUEST['k'] !== '';
$erro = null;
if (!$timer && $porChave) $erro = 'Chave inválida.';
elseif (!$timer && isset($_REQUEST['id'])) $erro = $usuario ? 'Timer não encontrado ou sem permissão.' : 'Faça login ou use a chave de acesso.';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="google" content="notranslate">
    <meta name="theme-color" content="#10151c">
    <link rel="icon" href="img/baja.png" type="image/png">
    <title><?= $timer ? $h($timer->getNome()) . ' - ' : '' ?>Timer Baja SAE BRASIL</title>
    <style>
        :root {
            --bg: #10151c; --panel: #1a222d; --line: #2b3746; --text: #eef2f6; --muted: #8fa0b3;
            --accent: #3d9bff; --ok: #35c46a; --warn: #f5b638; --danger: #ef5350;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            display: flex; flex-direction: column;
        }
        button, input { font: inherit; color: inherit; }
        button { cursor: pointer; border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 0.7rem 1rem; }
        button:hover { border-color: var(--muted); }
        button:disabled { opacity: 0.4; cursor: default; }
        a { color: var(--accent); }

        header {
            display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 1rem;
            padding-top: calc(0.6rem + env(safe-area-inset-top));
            border-bottom: 1px solid var(--line);
        }
        header h1 { font-size: 1.05rem; margin: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        header button { padding: 0.35rem 0.6rem; }
        #conn { font-size: 0.8rem; color: var(--muted); display: flex; align-items: center; gap: 0.35rem; white-space: nowrap; }
        #conn::before { content: ""; width: 0.6rem; height: 0.6rem; border-radius: 50%; background: var(--ok); }
        #conn.off::before { background: var(--danger); }
        #conn.pend::before { background: var(--warn); }

        #avisos > div { background: var(--warn); color: #000; padding: 0.5rem 1rem; text-align: center; font-size: 0.9rem; }

        main { flex: 1; display: grid; grid-template-columns: 1fr 22rem; min-height: 0; }
        #relogio { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1rem; text-align: center; min-width: 0; }
        #etapaNum { color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase; font-size: 0.9rem; }
        #etapaNome { font-size: clamp(1.6rem, 5vw, 3.5rem); font-weight: 600; margin: 0.2rem 0; }
        #tempo { font-size: clamp(5rem, 21vw, 17rem); font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
        body.aviso #tempo { color: var(--warn); }
        body.fim #tempo { color: var(--danger); }
        body.pausado #tempo { opacity: 0.55; }
        #barra { width: min(100%, 44rem); height: 0.6rem; background: var(--panel); border-radius: 1rem; overflow: hidden; margin: 1rem 0 0.6rem; }
        #barra > div { height: 100%; width: 0; background: var(--accent); }
        #status { color: var(--muted); min-height: 1.4em; }

        #controles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; width: min(100%, 44rem); margin-top: 1.2rem; }
        #btnPrincipal { grid-column: 1 / -1; font-size: 1.5rem; font-weight: 700; padding: 1rem; background: var(--ok); border-color: var(--ok); color: #04210f; }
        #btnPrincipal.pausar { background: var(--warn); border-color: var(--warn); color: #2b1d00; }
        #controles small { display: block; font-size: 0.7rem; color: var(--muted); }

        #lista { border-left: 1px solid var(--line); overflow-y: auto; padding: 0.5rem; }
        #lista button { display: flex; width: 100%; align-items: center; gap: 0.6rem; text-align: left; margin-bottom: 0.4rem; padding: 0.6rem 0.8rem; }
        #lista .n { color: var(--muted); width: 1.6rem; }
        #lista .nome { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #lista .t { font-variant-numeric: tabular-nums; color: var(--muted); }
        #lista button.ativa { border-color: var(--accent); background: #17324f; }
        #lista button.ativa .t { color: var(--text); }

        @media (max-width: 800px) {
            main { display: block; overflow-y: auto; }
            #relogio { padding-top: 1.5rem; }
            #lista { border-left: none; border-top: 1px solid var(--line); margin-top: 1rem; overflow: visible; padding-bottom: calc(0.5rem + env(safe-area-inset-bottom)); }
        }

        #confirma { display: none; position: fixed; inset: 0; z-index: 10; background: rgba(5, 8, 12, 0.88); align-items: center; justify-content: center; padding: 1rem; }
        #confirma.aberta { display: flex; }
        #confirma > div { width: min(100%, 34rem); background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 1.5rem; text-align: center; }
        #confirmaPergunta { font-size: clamp(1.6rem, 6vw, 2.4rem); font-weight: 700; }
        #confirmaDetalhe { color: var(--muted); font-size: 1.2rem; margin: 0.5rem 0 1.5rem; min-height: 1.4em; }
        #confirma button { display: block; width: 100%; font-size: 1.5rem; padding: 1.2rem; margin-top: 0.8rem; }
        #confirmaSim { font-weight: 700; background: var(--accent); border-color: var(--accent); color: #031a33; }

        .escolha { width: min(100%, 26rem); margin: 0 auto; padding: 1.5rem 1rem; }
        .escolha h2 { font-size: 1rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; margin: 1.5rem 0 0.6rem; }
        .escolha a.item { display: block; padding: 0.9rem 1rem; margin-bottom: 0.5rem; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; color: var(--text); text-decoration: none; }
        .escolha a.item:hover { border-color: var(--accent); }
        .escolha form { display: flex; gap: 0.5rem; }
        .escolha input[type=text] { flex: 1; min-width: 0; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 0.7rem 1rem; font-size: 1.3rem; letter-spacing: 0.2em; text-transform: uppercase; }
        .escolha .erro { color: var(--danger); }
    </style>
</head>
<body>
<?php if (!$timer) { ?>

<header>
    <h1>Timers<?= $usuario ? ' - ' . $h(EventoQuery::getCurrentEvent()->getNome()) : '' ?></h1>
    <?php if ($usuario) { ?><a href="index.php">Voltar</a><?php } ?>
</header>
<div class="escolha">
    <?php if ($erro) { ?><p class="erro"><?= $h($erro) ?></p><?php } ?>

    <?php if ($usuario) { ?>
        <h2>Seus timers</h2>
        <?php foreach ($disponiveis as $t) { ?>
            <a class="item" href="timer.php?id=<?= $t->getTimerId() ?>"><?= $h($t->getNome()) ?></a>
        <?php } ?>
        <?php if (!count($disponiveis)) { ?><p>Nenhum timer disponível para você neste evento.</p><?php } ?>
        <?php if ($podeConfigurar) { ?><p><a href="admin_timers.php">Configurar timers</a></p><?php } ?>
    <?php } ?>

    <h2>Entrar com chave de acesso</h2>
    <form action="timer.php" method="GET">
        <input type="text" name="k" maxlength="6" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABC234" required>
        <button type="submit">Abrir</button>
    </form>
    <?php if (!$usuario) { ?><p><a href="login.php">Fazer login</a></p><?php } ?>
</div>

<?php } else { ?>

<header>
    <?php if ($usuario) { ?><a href="timer.php" title="Voltar">&larr;</a><?php } ?>
    <h1><?= $h($timer->getNome()) ?></h1>
    <span id="conn">conectando</span>
    <button id="btnSom" title="Som"></button>
    <button id="btnTela" title="Tela cheia">⛶</button>
</header>
<div id="avisos"></div>
<main>
    <section id="relogio">
        <div id="etapaNum"></div>
        <div id="etapaNome"></div>
        <div id="tempo">--:--</div>
        <div id="barra"><div></div></div>
        <div id="status"></div>
        <div id="controles">
            <button id="btnPrincipal"></button>
            <button id="btnAnterior">⏮<small>Anterior</small></button>
            <button id="btnReiniciar">↺<small>Reiniciar etapa</small></button>
            <button id="btnProxima">⏭<small>Próxima</small></button>
            <button id="btnParar">⏹<small>Parar</small></button>
        </div>
    </section>
    <aside id="lista"></aside>
</main>
<div id="confirma">
    <div>
        <div id="confirmaPergunta"></div>
        <div id="confirmaDetalhe"></div>
        <button id="confirmaSim">CONFIRMAR</button>
        <button id="confirmaNao">Cancelar</button>
    </div>
</div>

<script>
(function () {
    const BOOT = <?= json_encode([
        'chave' => $timer->getEventoId() . '_' . $timer->getTimerId(),
        'endpoint' => 'timer_action.php?' . ($porChave ? 'k=' . urlencode($timer->getAccessKey()) : 'id=' . $timer->getTimerId()),
        'config' => $timer->getConfig(),
        'hash' => md5(json_encode($timer->getConfig())),
        'state' => $timer->getState(),
    ], $jsonFlags) ?>;

    const PARADO = { status: "stopped", step: 0, started_at: 0, remaining_ms: 0, at: 0 };
    const $ = (id) => document.getElementById(id);

    // ---- Local persistence -------------------------------------------------
    // Everything needed to keep running survives a reload with no network:
    // the state, the actions the server has not seen yet, and the clock offset.
    const store = {
        get(k, d) { try { const v = localStorage.getItem("timer_" + BOOT.chave + "_" + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem("timer_" + BOOT.chave + "_" + k, JSON.stringify(v)); } catch (e) {} },
    };

    let config = BOOT.config, hash = BOOT.hash;
    // Server clock minus this device's clock, as last measured. Until the first
    // sync answers, the device's own clock is taken as right.
    let offset = store.get("offset", 0);
    let melhorRtt = Infinity;
    let outbox = store.get("outbox", []);
    let state = BOOT.state || PARADO;
    let online = true;

    // This page may have been served from the service worker cache: what it
    // carries can be older than what this device did before the reload.
    const salvo = store.get("state", null);
    if (salvo && (outbox.length || salvo.at > state.at)) state = salvo;

    const agora = () => Date.now() + offset;
    const dur = (i) => config.etapas[i].tempo * 1000;
    const n = () => config.etapas.length;
    const clamp = (i) => Math.max(0, Math.min(n() - 1, i | 0));

    // ---- State -> what is on screen ----------------------------------------
    // A running state only says which step started when. Which step is current
    // now follows from the durations, so every device arrives at the same
    // answer without the server (or anyone) having to tick.
    function derivar(t) {
        const step = clamp(state.step);
        if (state.status === "paused") return { status: "paused", step: step, restante: Math.min(state.remaining_ms, dur(step)), k: 0 };
        if (state.status !== "running") return { status: "stopped", step: step, restante: dur(step), k: 0 };

        let el = Math.max(0, t - state.started_at), i = step, k = 0;
        if (config.loop) {
            const total = config.etapas.reduce((a, e) => a + e.tempo * 1000, 0);
            k = Math.floor(el / total) * n();
            el %= total;
        }
        while (el >= dur(i)) {
            el -= dur(i); i++; k++;
            if (i >= n()) {
                if (!config.loop) return { status: "finished", step: n() - 1, restante: 0, k: k };
                i = 0;
            }
        }
        return { status: "running", step: i, restante: dur(i) - el, decorrido: el, k: k };
    }

    // ---- Actions -----------------------------------------------------------
    function aplicar(acao, novo) {
        // Strictly increasing, so the server can order two actions taken in a row.
        novo.at = Math.max(Math.round(agora()), state.at + 1);
        state =Object.assign({ started_at: 0, remaining_ms: 0 }, novo);
        outbox.push({ id: Math.random().toString(36).slice(2, 12), acao: acao, at: novo.at, state: state });
        store.set("state", state);
        store.set("outbox", outbox);
        tick();
        sincronizar();
    }

    function irPara(acao, i) {
        const v = derivar(agora());
        i = clamp(i);
        if (v.status === "running") aplicar(acao, { status: "running", step: i, started_at: agora() });
        else if (v.status === "paused") aplicar(acao, { status: "paused", step: i, remaining_ms: dur(i) });
        else aplicar(acao, { status: "stopped", step: i });
    }

    function principal() {
        const v = derivar(agora());
        if (v.status === "running") aplicar("pausar", { status: "paused", step: v.step, remaining_ms: Math.round(v.restante) });
        else if (v.status === "paused") aplicar("retomar", { status: "running", step: v.step, started_at: Math.round(agora() - (dur(v.step) - v.restante)) });
        else aplicar("iniciar", { status: "running", step: v.status === "finished" ? 0 : v.step, started_at: agora() });
    }

    // Every action goes through a second, deliberate tap: a stray touch on a
    // phone must not start, stop or skip anything.
    let aoConfirmar = null;
    function confirmar(pergunta, detalhe, fn) {
        $("confirmaPergunta").textContent = pergunta;
        $("confirmaDetalhe").textContent = detalhe || "";
        aoConfirmar = fn;
        $("confirma").classList.add("aberta");
    }
    function fecharConfirma(executar) {
        const fn = aoConfirmar;
        aoConfirmar = null;
        $("confirma").classList.remove("aberta");
        if (executar && fn) fn();
    }
    $("confirmaSim").onclick = () => fecharConfirma(true);
    $("confirmaNao").onclick = () => fecharConfirma(false);
    $("confirma").onclick = (ev) => { if (ev.target === $("confirma")) fecharConfirma(false); };
    document.addEventListener("keydown", (ev) => { if (ev.key === "Escape") fecharConfirma(false); });

    const nomeEtapa = (i) => (i + 1) + ". " + config.etapas[i].nome;

    function pedirIrPara(acao, pergunta, i) {
        confirmar(pergunta, nomeEtapa(i), () => irPara(acao, i));
    }

    $("btnPrincipal").onclick = () => {
        const v = derivar(agora());
        const pergunta = v.status === "running" ? "Pausar o timer?" : v.status === "paused" ? "Retomar o timer?" :
            v.status === "finished" ? "Reiniciar a sequência?" : "Iniciar o timer?";
        // The button toggles, so if another device got there first while the
        // question was open, confirming must not undo what it did.
        confirmar(pergunta, nomeEtapa(v.status === "finished" ? 0 : v.step), () => { if (derivar(agora()).status === v.status) principal(); });
    };
    $("btnProxima").onclick = () => pedirIrPara("proxima", "Ir para a próxima etapa?", (derivar(agora()).step + 1) % n());
    $("btnAnterior").onclick = () => pedirIrPara("anterior", "Voltar para a etapa anterior?", (derivar(agora()).step + n() - 1) % n());
    $("btnReiniciar").onclick = () => pedirIrPara("reiniciar_etapa", "Reiniciar esta etapa?", derivar(agora()).step);
    $("btnParar").onclick = () => confirmar("Parar o timer?", "Volta para a primeira etapa.", () => aplicar("parar", { status: "stopped", step: 0 }));

    // ---- Server sync -------------------------------------------------------
    let sincronizando = false, proximaSync = null;

    function sincronizar() {
        if (sincronizando) return;
        sincronizando = true;
        clearTimeout(proximaSync);

        const enviados = outbox.slice();
        const ctrl = new AbortController();
        const limite = setTimeout(() => ctrl.abort(), 6000);
        const t0 = Date.now();

        fetch(BOOT.endpoint + "&h=" + hash, {
            method: enviados.length ? "POST" : "GET",
            body: enviados.length ? JSON.stringify({ acoes: enviados }) : undefined,
            signal: ctrl.signal, cache: "no-store", credentials: "same-origin",
        }).then((r) => {
            if (r.status === 403) { revogado(); throw 0; }
            if (!r.ok) throw 0;
            return r.json();
        }).then((d) => {
            const t1 = Date.now();
            // The round trip with the least delay gives the best estimate of the server clock.
            if (t1 - t0 <= melhorRtt + 50) {
                melhorRtt = Math.min(melhorRtt, t1 - t0);
                offset = d.now - (t0 + t1) / 2;
                store.set("offset", offset);
            }
            if (d.config) {
                config = d.config; hash = d.hash;
                montarLista(); carregarSons();
            }
            const ids = enviados.map((a) => a.id);
            outbox = outbox.filter((a) => ids.indexOf(a.id) < 0);
            store.set("outbox", outbox);
            // With nothing left to send the server has seen everything this device did, so its state stands.
            if (!outbox.length) {
                state = d.state || PARADO;
                store.set("state", state);
            }
            online = true;
        }).catch(() => {
            online = false;
        }).then(() => {
            clearTimeout(limite);
            sincronizando = false;
            tick();
            proximaSync = setTimeout(sincronizar, outbox.length && online ? 0 : (document.hidden ? 10000 : (online ? 2000 : 4000)));
        });
    }

    let acessoRevogado = false;
    function revogado() {
        acessoRevogado = true;
        aviso("revogado", "O acesso a este timer foi revogado. As ações feitas aqui não são mais enviadas.");
    }

    window.addEventListener("online", sincronizar);
    document.addEventListener("visibilitychange", () => { if (!document.hidden) { sincronizar(); travarTela(); } });

    // ---- Sound -------------------------------------------------------------
    // Decoded up front and played through Web Audio: nothing is fetched at the
    // moment a step changes, and one tap unlocks playback for the whole session.
    let audio = null, mudo = store.get("mudo", false);
    const buffers = {};

    function carregarSons() {
        if (!audio) {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            audio = new AC();
        }
        config.etapas.forEach((e) => [e.som_inicio, e.som_fim].forEach((f) => {
            if (!f || f in buffers) return;
            buffers[f] = null;
            fetch("sons/" + f.split("/").map(encodeURIComponent).join("/"))
                .then((r) => { if (!r.ok) throw 0; return r.arrayBuffer(); })
                .then((b) => audio.decodeAudioData(b, (buf) => { buffers[f] = buf; }, () => {}))
                .catch(() => { delete buffers[f]; });
        }));
    }

    function tocar(arquivos) {
        if (mudo || !audio || audio.state !== "running") return;
        let quando = audio.currentTime;
        arquivos.forEach((f) => {
            if (!f || !buffers[f]) return;
            const src = audio.createBufferSource();
            src.buffer = buffers[f];
            src.connect(audio.destination);
            src.start(quando);
            quando += buffers[f].duration;
        });
    }

    function destravarSom() {
        if (audio && audio.state !== "running") audio.resume().then(tick, () => {});
    }
    ["pointerdown", "keydown"].forEach((ev) => document.addEventListener(ev, destravarSom));

    $("btnSom").onclick = () => { mudo = !mudo; store.set("mudo", mudo); tick(); };

    // ---- Screen ------------------------------------------------------------
    // Without the wake lock (no HTTPS, old browser, battery saver) the screen
    // times out and this device goes silent, so the operator is told.
    let trava = null, semTrava = !("wakeLock" in navigator), tentarTravaApos = 0;
    function travarTela() {
        if (!("wakeLock" in navigator) || document.hidden || trava || Date.now() < tentarTravaApos) return;
        tentarTravaApos = Date.now() + 10000;
        navigator.wakeLock.request("screen").then(
            (t) => { trava = t; semTrava = false; t.addEventListener("release", () => { trava = null; }); },
            () => { semTrava = true; }
        );
    }

    $("btnTela").onclick = () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
    };

    const avisos = {};
    function aviso(id, texto) {
        if (texto && !avisos[id]) {
            avisos[id] = document.createElement("div");
            $("avisos").append(avisos[id]);
        }
        if (texto) avisos[id].textContent = texto;
        else if (avisos[id]) { avisos[id].remove(); delete avisos[id]; }
    }

    const fmt = (ms) => {
        const s = Math.max(0, Math.ceil(ms / 1000));
        return String(Math.floor(s / 60)).padStart(2, "0") + ":" + String(s % 60).padStart(2, "0");
    };

    function montarLista() {
        const lista = $("lista");
        lista.innerHTML = "";
        config.etapas.forEach((e, i) => {
            const b = document.createElement("button");
            b.innerHTML = '<span class="n"></span><span class="nome"></span><span class="t"></span>';
            b.children[0].textContent = i + 1;
            b.children[1].textContent = e.nome;
            b.onclick = () => pedirIrPara("ir_para", "Ir para esta etapa?", i);
            lista.append(b);
        });
    }

    let anterior = null;

    function tick() {
        const v = derivar(agora());
        const e = config.etapas[v.step];

        // A step that has just begun announces itself, whoever started it: this
        // device, another one, or the clock running out on the previous step.
        // Arriving in the middle of a step (page load, resume) stays quiet: a
        // resume is told apart by its step having started before the action.
        const marca = state.at + ":" + v.k;
        const doInicio = v.k > 0 || state.started_at >= state.at - 500;
        if (anterior && anterior.marca !== marca && v.status === "running" && v.decorrido < 3000 && doInicio) {
            const natural = anterior.at === state.at && v.k === anterior.k + 1;
            tocar([natural ? config.etapas[anterior.step].som_fim : null, e.som_inicio]);
            const ativa = $("lista").children[v.step];
            if (ativa && ativa.scrollIntoView) ativa.scrollIntoView({ block: "nearest" });
        }
        if (anterior && anterior.status === "running" && v.status === "finished") tocar([e.som_fim]);
        anterior = { marca: marca, at: state.at, k: v.k, step: v.step, status: v.status };

        $("etapaNum").textContent = "Etapa " + (v.step + 1) + " de " + n();
        $("etapaNome").textContent = e.nome;
        $("tempo").textContent = fmt(v.restante);
        document.title = fmt(v.restante) + " " + e.nome;
        $("barra").firstElementChild.style.width = (100 * (1 - v.restante / dur(v.step))) + "%";

        const rodando = v.status === "running";
        document.body.classList.toggle("aviso", rodando && v.restante <= 30000 && v.restante > 10000);
        document.body.classList.toggle("fim", (rodando && v.restante <= 10000) || v.status === "finished");
        document.body.classList.toggle("pausado", v.status === "paused");

        const prox = config.etapas[(v.step + 1) % n()];
        $("status").textContent =
            v.status === "paused" ? "Pausado" :
            v.status === "finished" ? "Sequência encerrada" :
            v.status === "stopped" ? "Parado" :
            (v.step + 1 < n() || config.loop) ? "A seguir: " + prox.nome : "Última etapa";

        const p = $("btnPrincipal");
        p.textContent = rodando ? "PAUSAR" : v.status === "paused" ? "RETOMAR" : v.status === "finished" ? "REINICIAR" : "INICIAR";
        p.classList.toggle("pausar", rodando);

        Array.prototype.forEach.call($("lista").children, (b, i) => {
            b.classList.toggle("ativa", i === v.step);
            b.children[2].textContent = (i === v.step && v.status !== "stopped") ? fmt(v.restante) : fmt(dur(i));
        });

        const c = $("conn");
        c.className = online ? (outbox.length ? "pend" : "") : "off";
        c.textContent = online ? (outbox.length ? "enviando" : "sincronizado") : "sem conexão";
        aviso("offline", online || acessoRevogado ? null :
            "Sem conexão: o timer continua neste aparelho" + (outbox.length ? " e " + outbox.length + " ação(ões) serão enviadas ao reconectar." : "."));

        $("btnSom").textContent = mudo ? "🔇" : "🔊";
        const temSom = config.etapas.some((x) => x.som_inicio || x.som_fim);
        aviso("som", (temSom && !mudo && audio && audio.state !== "running") ? "Toque em qualquer lugar para ativar o som neste aparelho." : null);

        aviso("tela", semTrava ? "Este aparelho não consegue manter a tela ligada. Desative o bloqueio automático de tela, ou o timer ficará mudo quando ela apagar." : null);

        if (rodando) travarTela();
        else if (trava) trava.release();
    }

    montarLista();
    carregarSons();
    tick();
    setInterval(tick, 200);
    sincronizar();

    if ("serviceWorker" in navigator) navigator.serviceWorker.register("timer_sw.js").catch(() => {});
})();
</script>

<?php } ?>
</body>
</html>
