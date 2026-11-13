<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Layout histórico de la Zebra ZQ630 Plus (móvil, 4", 203 dpi): ~100 × 75 mm. Es el formato ORIGINAL y su
 * salida es BYTE A BYTE idéntica a la que generaba el renderer antes de introducir los perfiles (test de
 * regresión). Por eso ignora el `$dpi` y mantiene los dots fijos del medio nativo de 4": cambiar su layout
 * rompería el contrato visual y la verificación de las etiquetas ya impresas.
 *
 * Lleva, según la simbología:
 *  - un QR (modelo 2, mag 6, ECC M) con el JSON firmado exacto (`^FDMA,`), fuente de verdad verificable;
 *  - un Code128 con el `label_id` del bulto, atajo para lectores láser 1D;
 *  - siempre: texto humano a la derecha (SKU, Unit N/M, Piece N/M, From, To).
 *
 * El nombre del producto NO se dibuja aquí: el formato grande histórico solo embebe el SKU (el nombre no
 * viaja en el payload firmado). Mantenerlo así preserva la identidad byte a byte del ZPL ya emitido.
 */
final readonly class StandardZplLayout implements ZplLayout
{
    public function profile(): LabelSizeProfile
    {
        return LabelSizeProfile::Standard;
    }

    public function render(
        SignedLabel $label,
        LabelSymbology $symbology,
        int $dpi,
        ?string $productName,
    ): string {
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
