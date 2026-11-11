<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/**
 * Renderiza, para el admin, las SIMBOLOGÍAS de una etiqueta como SVG inline (mismo origen, CERO CDN): el
 * QR firmado (escaneable, con el `qr_content` EXACTO que imprime la Zebra y que lee la app) y el Code128
 * del `label_id` (el mismo código de barras del ZPL). Así una etiqueta se puede escanear desde la pantalla
 * del admin igual que desde la app, sin mandar el payload firmado a ningún servicio externo.
 *
 * Es un colaborador de Presentation puro-de-render: no toca dominio ni base. Tolerante: si una librería
 * falla, devuelve '' (la vista degrada sin romper).
 */
final class LabelPreviewRenderer
{
    /** QR del contenido firmado (el string exacto del QR), como SVG inline escaneable. */
    public function qr(string $content, int $size = 220): string
    {
        if (trim($content) === '') {
            return '';
        }

        try {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(max(80, $size), 1),
                new SvgImageBackEnd,
            ));

            // El SVG de Bacon trae ancho/alto FIJOS (p. ej. 220px) + viewBox: metido en una caja menor se
            // DESBORDA (se corre a la derecha y pisa la línea de separación). Se fuerzan a 100% para que
            // escale exacto al contenedor, centrado y sin desbordar (el viewBox conserva la proporción).
            $svg = $this->inlineSvg($writer->writeString($content));
            $svg = preg_replace('/(<svg\b[^>]*?)\s(?:width|height)="\d+(?:\.\d+)?"/', '$1', $svg, 2);

            return is_string($svg)
                ? (string) preg_replace('/<svg\b/', '<svg width="100%" height="100%" preserveAspectRatio="xMidYMid meet"', $svg, 1)
                : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** Code128 del id del bulto (el mismo del ^BC del ZPL), como SVG inline. */
    public function barcode(string $value, int $height = 54): string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            $svg = (new BarcodeGeneratorSVG)->getBarcode(
                $value,
                BarcodeGeneratorSVG::TYPE_CODE_128,
                2,
                max(24, $height),
            );

            // El SVG trae ancho fijo (p. ej. 642px) + viewBox: se fuerza a 100% para que escale al ancho de
            // la tarjeta sin desbordar, conservando la proporción del Code128 (crítico para que escanee).
            $svg = preg_replace('/(<svg\b[^>]*?)\swidth="\d+(?:\.\d+)?"/', '$1 width="100%"', $this->inlineSvg($svg), 1);

            return is_string($svg) ? $svg : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** Quita el prólogo XML/doctype para poder incrustar el `<svg>` directo en el HTML. */
    private function inlineSvg(string $svg): string
    {
        $pos = strpos($svg, '<svg');

        return $pos === false ? '' : substr($svg, $pos);
    }
}
