<?php
/**
 * The certificate site's front door.
 *
 * Used to be an event selector followed by a CPF field. Both are gone: one
 * search across every event replaced the two-step flow, so there is nothing
 * left to choose here.
 */

header('Location: /buscar', true, 302);
header('Cache-Control: no-store');
exit;
