<?php
header('Content-Type: text/html; charset=utf-8');

//TODO: use Propel properly
$servername = "localhost";
$username = $manager->getConfiguration()['user'];
$password = $manager->getConfiguration()['password'];
$dbname = "baja_resultados";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} 
//echo "Connected successfully";

$sql = "SELECT * FROM evento WHERE tem_certificado = 1 ORDER BY titulo";
$result = $conn->query($sql);
               
?>
<!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/html">
        <head>
            <script async src="https://www.googletagmanager.com/gtag/js?id=G-KBKCF1HW4R"></script>
            <script>
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag('js', new Date());

              gtag('config', 'G-KBKCF1HW4R');
            </script>
            <meta name="viewport" content="width=device-width" />
            <meta charset="UTF-8" />
            <meta http-equiv="Content-Language" content="pt-br">
            <meta name="google" content="notranslate">
            <script src="js/jquery-latest.min.js"></script>
            <script src="js/jquery.tablesorter.js"></script>
            <script src="js/jquery.tablesorter.widgets.js"></script>
            <link class="theme" rel="stylesheet" href="css/theme.blue.css">
            <link class="theme" rel="stylesheet" href="css/theme.blue.disable.hover.css">            
			<link rel="icon" href="img/baja.png" type="image/png">
            <!--[if IE]>
            <script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
            <![endif]-->
            <title>Baja SAE BRASIL- Certificados</title>
        </head>
        <script id="js">
            $(function(){
                var v= $("table");
                $("table").tablesorter({
                    theme : 'blue',
                    widgets: [ 'zebra' ]
                });
            });
        </script>
        <body>

<div style="max-width:400px; margin: 0 auto">
    <table class="tablesorter">
        <thead>
            <tr class="tablesorter-ignoreRow"> 
                <th class="sorter-false">
                    <span style="float:left; width:30%; ; text-align:left; line-height:40px">
					    <img src="img/baja_grande.png" class="logo">
                    </span>
                    <span style="float:right; height:30%; text-align:right">
                        <img src="img/sae.png" class="logo" width="200px">
                    </span>
                </th>
            </tr>	
            <tr class="tablesorter-ignoreRow" style="height: 40px">
                <th class="sorter-false" style="line-height: 22px;">Emissão de certificados das competições de Baja SAE BRASIL</th>
            </tr>
        </thead>
<tr>
<td>
<br /><br /> <form action="c/novo/certificado" method="post">
        <label for="evt">Selecione o evento</label><br />
			<select name="evt">
			<?php
				if ($result->num_rows > 0) {
					// output data of each row
					while($row = $result->fetch_assoc()) {
						echo " <option value='". $row["evento_id"]."'>". $row["titulo"]."</option>";
					}
				} else {
					echo "0 results";
				}
				$conn->close();
			?>
			</select>
        
		<br /><br />
		
        <label for="cpf">CPF (Somente números)</label><br />
        <input type="number" id="cpf" name="cpf" size="11" />
        <br /><br />
        <input type="submit" value="Obter Certificado"/>
		</form>
   </body>
        </html>
