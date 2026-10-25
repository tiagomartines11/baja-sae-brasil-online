<?php
use Baja\Site\Template;
use Baja\Site\CachePolicy;
use Baja\Site\CleanUrl;
use Baja\Model\EventoQuery;

// A public page, cached by Cloudflare (docs/results-caching.md). It reads
// no query parameter, so any is dropped by a redirect.
CleanUrl::enforce([]);
CachePolicy::send(EventoQuery::getCurrentEvent());

Template::printHeader("");
?>
<div>
    <!--img src="img/aviso_18.gif" style="min-width: 520px; max-width: 800px; width: 100%"/-->
</div>
<?php
Template::printFooter();