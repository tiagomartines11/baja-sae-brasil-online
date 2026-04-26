<!doctype html>

<html lang="pt-br">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=0.95">

  <title>Overlay1</title>

  <script src="js/jquery-latest.min.js"></script>
  <script src="js/jquery.keyframes.min.js"></script>
  <script src="js/overlay.js"></script>
  <link rel="stylesheet" href="css/overlay.css"/>

  <script type="text/javascript">
    var id=1;
  </script>


</head>

<body>
  
    
  <div class="positionScroll pshidden">
    <div id="psmarquee" class="positionScrollMarquee">
    </div>
  </div>

  <div class="enduroTotals ethidden">
    <div class="enduroTotalsHead">
      <div class="enduroTotalsTitle">ENDURO</div>

      <div class='etLineTitle'><div class='etPosTitle'><span>Pos</span></div><div class='etNumTitle'><span>Carro</span></div><div class='etMiddleTitle'>Equipe/Escola</div><div class='etVtsTitle'><span>Voltas</span></div></div><hr>
    </div>
    <div id="enduroTotals">
      <div class='etLine'>
        <div class='etPos'>
          <span>Posição</span>
        </div>
        <div class='etNum'>
          <span>Carro</span>
        </div>
        <div class='etMiddle'>
          <span class='etEq'>Equipe/Escola</span>
        </div>
        <div class='etVts'>
          <span>Voltas</span>
        </div>
      </div>

      <hr>
    </div>
    
  </div>


  <script>
    evt = '<?= $_REQUEST['evt'] ?>';
    ovl = <?= $_REQUEST['ovl'] ?>;
  </script>

</body>

</html>