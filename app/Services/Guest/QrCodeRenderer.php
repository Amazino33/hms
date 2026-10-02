<?php

namespace App\Services\Guest;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Black-on-white QR codes for the printed table cards, room stickers and
 * posters (Phase 1B). Always plain black on white with the standard quiet
 * zone — coloured or low-contrast codes fail on cheap phone cameras in dim
 * bars. Error correction M copes with a scuffed or wet card.
 */
class QrCodeRenderer
{
    /** Inline SVG markup, for the admin preview. */
    public function svg(string $url): string
    {
        return (new QRCode($this->options(QROutputInterface::MARKUP_SVG, scale: 5)))->render($url);
    }

    /** Raw PNG bytes — for "Download PNG", and embedded in the print PDFs. */
    public function png(string $url, int $scale = 12): string
    {
        return (new QRCode($this->options(QROutputInterface::GDIMAGE_PNG, scale: $scale)))->render($url);
    }

    public function pngDataUri(string $url, int $scale = 12): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($url, $scale));
    }

    private function options(string $outputType, int $scale): QROptions
    {
        $options = [
            'outputType' => $outputType,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'svgAddXmlHeader' => false,
            'drawLightModules' => true,
        ];

        // A solid white PNG, never transparent: a transparent code dropped
        // onto a coloured card design is exactly what fails to scan.
        if ($outputType === QROutputInterface::GDIMAGE_PNG) {
            $options['imageTransparent'] = false;
            $options['bgColor'] = [255, 255, 255];
        }

        return new QROptions($options);
    }
}
