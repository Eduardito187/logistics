<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\Contract\LabelRenderer;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Genera ZPL para la Zebra ZQ630 Plus (impresora móvil térmica, 203 dpi, 4").
 *
 * Produce un string ZPL (no una imagen): la app lo manda tal cual a la impresora. Según la simbología
 * configurada (ver {@see LabelSymbology}), la etiqueta lleva:
 *  - un código QR (modelo 2) con el JSON firmado exacto (lo que escanea y verifica la app offline);
 *  - un código de barras Code128 con el id corto del bulto (el `id` del payload), legible por lector 1D;
 *  - siempre: texto humano legible al costado: SKU, "Unit N/M", "Piece N/M", origen y destino.
 *
 * El QR y el Code128 son redundantes a propósito: el QR es la fuente de verdad verificable offline; el
 * barcode es un atajo secundario para los lectores láser de almacén. El contenido del QR no cambia nunca
 * (es byte a byte el `toQrContent()`); la simbología solo decide qué bloques se dibujan.
 *
 * Estructura del ZPL generado (ZQ630, ~100x75mm, 203 dpi):
 *   ^XA                          inicio de etiqueta
 *   ^CI28                        juego de caracteres UTF-8
 *   ^PW799 / ^LL0609             ancho (~4") y alto de impresión en dots a 203 dpi
 *   ^LH0,0                       origen de coordenadas
 *   ^FO30,30 ^BQN,2,6 ^FDMA,…^FS QR: modelo 2, magnificación 6, corrección media (M), modo automático (A)
 *   ^FO.. ^A0N,.. ^FD…^FS        líneas de texto escalable a la derecha del QR
 *   ^FO30,470 ^BY2 ^BCN,90,Y,N,N ^FD<id>^FS  Code128 con el id del bulto, al pie de la etiqueta
 *   ^XZ                          fin de etiqueta
 *
 * El JSON firmado y el id del bulto no contienen nunca `^` ni `~` (los prefijos de control de ZPL):
 * base64url usa `-`/`_`, el id es un ULID (A-Z0-9), y los patrones de SKU/pedido/nodo los prohíben, así
 * que es seguro embeberlos en el `^FD` sin escapar.
 */
final readonly class ZplLabelRenderer implements LabelRenderer
{
    public function render(SignedLabel $label, LabelSymbology $symbology = LabelSymbology::Both): string
    {
        $payload = $label->payload;

        $unit = "{$payload->unit->index}/{$payload->unit->total}";
        $piece = "{$payload->piece->index}/{$payload->piece->total}";
        $destination = $payload->isForCustomer() ? 'CUSTOMER' : $payload->destination;

        $lines = [
            '^XA',
            '^CI28',
            '^PW799',
            '^LL0609',
            '^LH0,0',
        ];

        if ($symbology->includesQr()) {
            // Código QR con el JSON firmado: corrección de error media, modo de entrada automático.
            $lines[] = '^FO30,30';
            $lines[] = '^BQN,2,6';
            $lines[] = '^FDMA,'.$label->toQrContent().'^FS';
        }

        // Texto humano a la derecha del QR (siempre presente).
        $lines[] = '^FO360,40^A0N,40,40^FD'.$payload->sku.'^FS';
        $lines[] = '^FO360,100^A0N,30,30^FDUnit '.$unit.'^FS';
        $lines[] = '^FO360,145^A0N,30,30^FDPiece '.$piece.'^FS';
        $lines[] = '^FO360,205^A0N,30,30^FDFrom '.$payload->origin.'^FS';
        $lines[] = '^FO360,250^A0N,30,30^FDTo '.$destination.'^FS';

        if ($symbology->includesBarcode()) {
            // Code128 al pie con el id corto del bulto: ^BY2 fija el ancho de módulo; ^BC orientación
            // normal, 90 dots de alto, con línea de interpretación (el id impreso debajo de las barras).
            // A 203 dpi: 470 + 90 de barras + ~30 de texto ≈ 590 dots, dentro del alto ^LL0609.
            $lines[] = '^FO30,470';
            $lines[] = '^BY2';
            $lines[] = '^BCN,90,Y,N,N';
            $lines[] = '^FD'.$payload->id.'^FS';
        }

        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }
}
