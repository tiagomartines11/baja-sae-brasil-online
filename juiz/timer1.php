<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cronometragem Projeto Baja SAE BRASIL</title>
  <style>
    .timer {
      border: 2px solid black;
      margin: 0.5rem;
      padding: 0.5rem;
      max-width: 20rem;
    }
    .timer.active {
      background-color: cornflowerblue;
    }
  </style>
</head>
<body>
  <div id="timers"></div>
  <h1 id="timerNome"></h1>
  <h2 id="timerTempo"></h2>
  <br><br><br>
  <button id="btnIniciar">INICIAR</button>
  <br>
  <button id="btnParar">PARAR</button>
  <br>
  <input type="number" id="gotoInput">
  <button id="btnIrPara">IR PARA</button>

  <template id="timerTemplate">
    <div class="timer">
      <h3>#1</h3>
      <h1>Timer Nome</h1>
      <h2>000:00</h2>
    </div>
  </template>

  <script>
    const timerDefs = [
      { nome: "Apresentação",  tempo: 240, som_inicio: "Apresentacao.wav",      som_fim: false },
      { nome: "1 Minuto",      tempo: 60,  som_inicio: "01 minuto.wav",          som_fim: false },
      { nome: "Perguntas",     tempo: 300, som_inicio: "Perguntas.wav",          som_fim: false },
      { nome: "Feedback",      tempo: 180, som_inicio: "Feedback.wav",           som_fim: false},
      { nome: "Troca carros",  tempo: 180, som_inicio: "Troca de Carros.wav", som_fim: false},
      { nome: "Apresentação",  tempo: 240, som_inicio: "Apresentacao.wav",      som_fim: false },
      { nome: "1 Minuto",      tempo: 60,  som_inicio: "01 minuto.wav",          som_fim: false },
      { nome: "Perguntas",     tempo: 300, som_inicio: "Perguntas.wav",          som_fim: false },
      { nome: "Feedback",      tempo: 180, som_inicio: "Feedback.wav",           som_fim: false},
      { nome: "Troca equipe",  tempo: 300, som_inicio: "Troca de Equipe.wav",    som_fim: false }
    ];

    const sequencia = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

    let i = 0;
    let UIClock;
    let endTime = Date.now();

    function secsToMin(secs) {
      const s = Math.max(0, secs);
      return Math.floor(s / 60).toString().padStart(2, "0") + ":" + (s % 60).toString().padStart(2, "0");
    }

    function setUITimerAtivo(index) {
      document.querySelectorAll(".timer").forEach((el) => {
        el.classList.toggle("active", el.id === "timer" + index);
      });
    }
    
    function setUITime(index, secs) {
      const el = document.querySelector("#timer" + index + " > h2");
      if (el) el.textContent = secsToMin(secs);
    }

    function createUITimer(defIndex, seqIndex) {
      const template = document.getElementById("timerTemplate");
      const clone = template.content.cloneNode(true);
      const div  = clone.querySelector("div");
      div.id = "timer" + seqIndex;
      clone.querySelector("h3").textContent = "#" + (seqIndex + 1);
      clone.querySelector("h1").textContent = timerDefs[defIndex].nome;
      clone.querySelector("h2").textContent = secsToMin(timerDefs[defIndex].tempo);
      document.getElementById("timers").appendChild(clone);
    }

    function populateUI() {
      sequencia.forEach((defIdx, seqIdx) => createUITimer(defIdx, seqIdx));
    }

    function playSound(file) {
      new Audio("./sons/" + file).play();
    }

    function clockStart() {
      clearInterval(UIClock);
      UIClock = setInterval(function () {
        const distance = Math.floor((endTime - Date.now()) / 1000);
        setUITime(i, distance);
        if (distance <= 0) {
          clearInterval(UIClock);
          next();
        }
      }, 1000);
    }

    function start() {
      endTime = Date.now() + timerDefs[sequencia[i]].tempo * 1000;
      setUITimerAtivo(i);
      if (timerDefs[sequencia[i]].som_inicio) {
        playSound(timerDefs[sequencia[i]].som_inicio);
      }
      clockStart();
    }

    function next() {
      if (timerDefs[sequencia[i]].som_fim) {
        playSound(timerDefs[sequencia[i]].som_fim);
      }
      i = (i >= sequencia.length - 1) ? 0 : i + 1;
      start();
    }

    function stop() {
      clearInterval(UIClock);
    }

    function gotoTimer(setPoint) {
      i = setPoint;
      start();
    }

    // Wire up buttons after DOM is ready
    document.getElementById("btnIniciar").addEventListener("click", start);
    document.getElementById("btnParar").addEventListener("click", stop);
    document.getElementById("btnIrPara").addEventListener("click", function () {
      const val = parseInt(document.getElementById("gotoInput").value, 10);
      if (!isNaN(val) && val >= 0 && val <= sequencia.length - 1) {
        gotoTimer(val);
      }
    });

    populateUI();
  </script>
</body>
</html>