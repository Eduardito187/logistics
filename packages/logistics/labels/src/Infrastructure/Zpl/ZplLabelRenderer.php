<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\Contract\LabelRenderer;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Genera ZPL para la Zebra ZQ630 Plus (impresora móvil térmica, 203 dpi, 4").
 *
 * Produce un string ZPL (no una imagen): la app lo manda tal cual a la impresora. La etiqueta lleva:
 *  - un código QR (modelo 2) con el JSON firmado exacto (lo que escanea y verifica la app offline), y
 *  - texto humano legible al costado: SKU, "Unit N/M", "Piece N/M", origen y destino.
 *
 * Estructura del ZPL generado:
 *   ^XA                          inicio de etiqueta
 *   ^CI28                        juego de caracteres UTF-8
 *   ^PW799 / ^LL0609             ancho (~4") y alto de impresión en dots a 203 dpi
 *   ^LH0,0                       origen de coordenadas
 *   ^FO30,30 ^BQN,2,6 ^FDMA,…^FS QR: modelo 2, magnificación 6, corrección media (M), modo automático (A)
 *   ^FO.. ^A0N,.. ^FD…^FS        líneas de texto escalable
 *   ^XZ                          fin de etiqueta
 *
 * El JSON firmado no contiene nunca `^` ni `~` (los prefijos de control de ZPL): base64url usa `-`/`_`,
 * y los patrones de SKU/pedido/nodo los prohíben, así que es seguro embeberlo en el `^FD` sin escapar.
 */
final readonly class ZplLabelRenderer implements LabelRenderer
{
    public function render(SignedLabel $label): string
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
            // Código QR con el JSON firmado: corrección de error media, modo de entrada automático.
            '^FO30,30',
            '^BQN,2,6',
            '^FDMA,'.$label->toQrContent().'^FS',
            // Texto humano a la derecha del QR.
            '^FO360,40^A0N,40,40^FD'.$payload->sku.'^FS',
            '^FO360,100^A0N,30,30^FDUnit '.$unit.'^FS',
            '^FO360,145^A0N,30,30^FDPiece '.$piece.'^FS',
            '^FO360,205^A0N,30,30^FDFrom '.$payload->origin.'^FS',
            '^FO360,250^A0N,30,30^FDTo '.$destination.'^FS',
            '^XZ',
        ];

        return implode("\n", $lines)."\n";
    }
}
