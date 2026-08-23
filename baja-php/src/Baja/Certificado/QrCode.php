<?php

namespace Baja\Certificado;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
// Aliased: PHP class names are case-insensitive, so importing the vendor's
// QRCode into this namespace would collide with the QrCode declared below.
use chillerlan\QRCode\QRCode as QRCodeRenderer;
use chillerlan\QRCode\QROptions;

/**
 * The QR code printed on the certificate, as a PNG data URI.
 *
 * PNG rather than SVG on purpose. dompdf's SVG support goes through
 * php-svg-lib and is patchy — and separately, four of the five open dompdf
 * advisories against the version in use are about its SVG and remote-image
 * handling. A base64 PNG uses none of that.
 */
final class QrCode
{
    /**
     * Rendered at ten pixels per module and scaled down by CSS in the
     * template. At the printed size a 1:1 render lands between device pixels
     * and the module edges soften; oversampling and letting the rasterizer
     * downscale keeps them crisp on paper, which is where these get scanned.
     */
    private const SCALE = 10;

    /**
     * Four modules of quiet zone, the specified minimum. Cropping it flush
     * against artwork is the usual reason a code scans on one phone and not
     * another.
     */
    private const QUIET_ZONE = 4;

    public static function dataUri(string $content): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            // M corrects around 15% damage, which is plenty for a code printed
            // on clean stock and never overprinted. H would spend a third of
            // the symbol's capacity on redundancy and force a denser grid for
            // no gain here.
            'eccLevel'        => EccLevel::M,
            'scale'           => self::SCALE,
            'quietzoneSize'   => self::QUIET_ZONE,
            'outputBase64'    => true,
        ]);

        return (new QRCodeRenderer($options))->render($content);
    }
}
