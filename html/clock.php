<?php
use Baja\Site\Template;

Template::printHeader("");
?>
<script src="js/ServerDate.js"></script>
<script>
    ServerDate.amortizationThreshold = 100; // ms
    ServerDate.synchronizationIntervalDelay = 1 * 60 * 1000;
    function startTime() {
        var today = ServerDate;
        var h = today.getHours();
        var m = today.getMinutes();
        var s = today.getSeconds();
        var t = today.getTimezoneOffset()/60 * -1;
        h = checkTime(h);
        m = checkTime(m);
        s = checkTime(s);
        if (ServerDate.getPrecision() > 50)
            document.getElementById('clock').innerHTML = "sincronizando..."
        else
            document.getElementById('clock').innerHTML =
                h + ":" + m + ":" + s + " GMT" + (t >= 0 ? "+"+t : t);
        setTimeout(startTime, 1000);
    }
    function checkTime(i) {
        if (i < 10) {i = "0" + i};  // add zero in front of numbers < 10
        return i;
    }
    $(document).ready(function() { startTime(); });
</script>

<div class="clock">
    <span style="font-size: 60px">Horário Oficial</span>
    <div id="clock"></div>
</div>
<?php
Template::printFooter();