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
    <div class="clock">
        <img src="img/enduro.jpg" />
    </div>
<?php
Template::printFooter();