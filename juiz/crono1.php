<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Cronometragem Projeto Baja SAE BRASIL</title>
  <style>
    div .timer {
      border: 2px solid black;
      margin: 0.5rem;
      padding: 0.5rem;
      max-width: 20rem;
    }

    div .active {
      background-color: cornflowerblue;
    }
  </style>
</head>
<body onload="populateUI()">
  <div id="timers">

  </div>
  <h1 id="timerNome"></h1>
  <h2 id="timerTempo"></h2>
  <br><br><br>
  <button onclick="start()">INICIAR</button>
  <br>
  <button onclick="stop()">PARAR</button>
  <br>
  <input type="number" id="gotoInput"/><button onclick="gotoButtonAction()">IR PARA</button>

  <template id="timerTemplate">
    <div class="timer">
      <h3>#1</h3>
      <h1>Timer Nome</h1>
      <h2>000:00</h2>
    </div>
  </template>


</body>
<script>

  const timerDefs = [
    {
      nome: "Apresentação",
      tempo: 240,
      som_inicio: "Apresentacao.wav",
      som_fim: false
    },
    {
      nome: "1 Minuto",
      tempo: 60,
      som_inicio: "01 minuto.wav",
      som_fim: false
    },
    {
      nome: "Perguntas",
      tempo: 300,
      som_inicio: "Perguntas.wav",
      som_fim: false
    },
    {
      nome: "Troca equipe",
      tempo: 300,
      som_inicio: "Troca de Equipe.wav",
      som_fim: false
    }
  ]

  //const sequencia = [0,1,2,3,4,0,1,2,3,4,0,1,2,3,5]
  const sequencia = [0,1,2,3]

  function setUITImerAtivo(id){
    const allUITimers = document.querySelectorAll("div.timer")

    allUITimers.forEach((e) => {
      if (e.id == "timer"+id) {
        e.classList.add("active")
      } else {
        e.classList.remove("active")
      }
    })
  }

  function createUITimer(id, name) {
    let body = document.getElementById("timers");
    let template = document.getElementById('timerTemplate');

    let clone = template.content.cloneNode(true);
    let div = clone.querySelectorAll("div")[0];
    div.id="timer"+name
    let nome = clone.querySelectorAll("h1")[0];
    let tempo = clone.querySelectorAll("h2")[0];
    let ordem = clone.querySelectorAll("h3")[0];

    nome.innerHTML = timerDefs[id].nome
    tempo.innerHTML = secsToMin(timerDefs[id].tempo)
    ordem.innerHTML = "#" + name

    body.appendChild(clone);
  }

  function populateUI() {
    sequencia.forEach((e,i,a) => createUITimer(e,i))
  }

  let i = 0

  let timer

  let endTime = new Date().getTime()

  function start() {
    
    endTime = new Date().getTime() + timerDefs[sequencia[i]].tempo*1000

    setUITImerAtivo(i)

    if (timerDefs[sequencia[i]].som_inicio) {
      playSound(timerDefs[sequencia[i]].som_inicio)
    }

    clockStart()

    //timer = setTimeout(next, timerDefs[i].tempo);

  }

  function next() {
      
    if (timerDefs[sequencia[i]].som_fim) {
      playSound(timerDefs[sequencia[i]].som_fim)
    }

    if (i>=(sequencia.length-1)) {
      i=0
    } else {
      i++
    }

    start()

  }

  function playSound(file) {
    let audio = new Audio('./sons/'+file)
    audio.play()
  }

  function stop() {
    clearInterval(UIClock)
  }

  function goto(setPoint) {
    i = setPoint

    start()
  }

  function setUITime(id, time) {
    const timeEl = document.querySelectorAll("#timer"+id+" > h2", true)[0]
    timeEl.innerHTML = secsToMin(time)
  }

  function secsToMin(secs) {
    return Math.floor(secs / 60).toString().padStart(2,"0") + ":" + (secs % 60).toString().padStart(2,"0")
  }

  function gotoButtonAction() {
    const val = document.getElementById("gotoInput").value

    if (val <= (sequencia.length-1)) {
      goto(val)
    }
  }

  let UIClock

  function clockStart(){

    UIClock = setInterval(function() {

      let now = new Date().getTime()

      let distance = Math.floor((endTime - now)/1000)

      setUITime(i, distance)

      if (distance <= 0) {
        clearInterval(UIClock);
        next()
      }
    }, 1000)
  }

</script>
</html>