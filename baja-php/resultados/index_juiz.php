<?php
use Baja\Site\CachePolicy;
use Baja\Site\Template;

// The judge area's "Resultados" link. The same menu as index.php, linking to
// prova.php, which serves judges the live page. Loaded with the forum
// session (it is not one of the public pages in the nginx vhost), so never
// cached.
CachePolicy::sendPrivate();

Template::printHeader("");
?>
<div>
    <!--img src="img/aviso_18.gif" style="min-width: 520px; max-width: 800px; width: 100%"/-->
</div>
<?php
Template::printFooter();
