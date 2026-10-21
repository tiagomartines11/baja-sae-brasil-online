<?php

namespace Baja\Certificado;

/**
 * Response headers shared by every public certificate page.
 */
final class Http
{
    /**
     * Applied to /buscar, /verificar/{token} and the PDF download.
     *
     * These pages carry a participant's full name, so:
     *
     * - no-store, because edge-caching them would put personal data on
     *   Cloudflare's network. That used to buy something, back when every
     *   verification meant a dompdf run; now that the expensive render is
     *   behind its own route it buys nothing.
     * - noindex/nofollow as a header rather than a robots.txt Disallow.
     *   Disallow asks a crawler not to fetch the page, which does not stop it
     *   indexing the URL itself, and the URL is the identifier.
     * - no-referrer, so that a participant following a link off one of these
     *   pages does not hand their certificate's URL to the destination.
     */
    public static function sendPrivateHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
    }
}
