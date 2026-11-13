<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Layout con Data Matrix del `label_id` desnudo para los perfiles más chicos (roadmap §4.2/§4.6):
 *  - `Lite40x30` 40 × 30 mm: rollos de escritorio de 40 mm; SKU + nombre corto + id al pie.
 *  - `Micro`     30 × 15 mm (solo 300 dpi): joyería/cables; SKU + últimos 6 del id (sin nombre, excepción
 *    aprobada por el dueño §7.4 por falta de espacio físico).
 *
 * El Data Matrix (`^BXN`) es el símbolo más compacto y el estándar de etiquetado de piezas chicas; lo leen
 * ML Kit y los imagers 2D de almacén. Lleva el `label_id` desnudo, igual que el QR Lite: no toca el
 * contrato del JSON firmado. La app debe tener habilitado el formato Data Matrix para leerlo (roadmap §4.8).
 */
final readonly class CompactDataMatrixZplLayout implements ZplLayout
{
    use SanitizesZplText;

    public function __construct(private LabelSizeProfile $profile)
    {
        if ($this->profile !== LabelSizeProfile::Lite40x30 && $this->profile !== LabelSizeProfile::Micro) {
            throw new \InvalidArgumentException('CompactDataMatrixZplLayout solo soporta los perfiles Lite40x30 y Micro.');
        }
    }

    public function profile(): LabelSizeProfile
    {
        return $this->profile;
    }

    public function render(
        SignedLabel $label,
        LabelSymbology $symbology,
        int $dpi,
        ?string $productName,
    ): string {
        $payload = $label->payload;

        $width = $this->profile->widthDots($dpi);
        $height = $this->profile->heightDots($dpi);

        // Altura del módulo del Data Matrix (dots). Entre más dpi, más dots por módulo para legibilidad.
        $moduleHeight = $dpi >= 300 ? 8 : 5;

        $textX = (int) round($width * 0.36);
        $fieldWidth = max(1, $width - $textX - (int) round($width * 0.03));

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.$width,
            '^LL'.$height,
            '^LH0,0',
            '^MNY',
            '^PR2',
            '^MD4',
            // Data Matrix con el label_id desnudo: ^BXN,<alto módulo>,<calidad 200>,<cols>,<filas>.
            '^FO'.(int) round($width * 0.03).','.(int) round($height * 0.08).'^BXN,'.$moduleHeight.',200,18,18^FD'.$payload->id.'^FS',
            // SKU destacado.
            '^FO'.$textX.','.(int) round($height * 0.1).'^A0N,'.(int) round($height * 0.14).','.(int) round($height * 0.11).'^FB'.$fieldWidth.',2,0,L^FD'.$payload->sku.'^FS',
        ];

        if ($this->profile->showsProductName()) {
            // Nombre del producto (regla «nombre + SKU»); si falta, usa el SKU para no quedar vacío.
            $name = $this->sanitizeName($productName, 24);
            if ($name === '') {
                $name = $payload->sku;
            }
            $lines[] = '^FO'.$textX.','.(int) round($height * 0.42).'^A0N,'.(int) round($height * 0.1).','.(int) round($height * 0.08).'^FB'.$fieldWidth.',2,0,L^FD'.$name.'^FS';
            // label_id completo legible al pie (respaldo de carga manual).
            $lines[] = '^FO'.(int) round($width * 0.03).','.(int) round($height * 0.86).'^A0N,'.(int) round($height * 0.08).','.(int) round($height * 0.06).'^FD'.$payload->id.'^FS';
        } else {
            // Micro: sin espacio para nombre ni id completo; solo los últimos 6 del id (están impresos).
            $lines[] = '^FO'.$textX.','.(int) round($height * 0.55).'^A0N,'.(int) round($height * 0.16).','.(int) round($height * 0.13).'^FD...'.substr($payload->id, -6).'^FS';
        }

        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }
}
