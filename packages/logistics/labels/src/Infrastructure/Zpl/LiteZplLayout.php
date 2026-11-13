<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Layout chico Lite 50 × 25 mm para productos pequeños (celulares, accesorios, cajas chicas).
 *
 * Diferencias con el Standard (roadmap §4.2/§4.6):
 *  - El código 2D es un QR con el `label_id` DESNUDO (ECC Q, entrada automática: `^FDQA,<id>`), NO el JSON
 *    firmado. Es el mismo valor que hoy ya viaja en el Code128 del Standard, así que NO cambia el contrato
 *    del QR con la app (no se sube `v`). En 50 × 25 el JSON completo no entra legible; el id sí (QR v2).
 *  - No lleva Code128: el ULID en Code128 mide ~80 mm, no cabe (roadmap §4.2).
 *  - Texto corto: SKU + nombre del producto (regla «nombre + SKU»; si falta el nombre usa el SKU, nunca
 *    vacío) + «U N/M» + el `label_id` legible al pie (respaldo para carga manual sin lector).
 *
 * Los dots se calculan desde el DPI de la impresora (203 → 400 × 200; 300 → 591 × 295): el perfil es en mm.
 * La simbología se ignora: en chico SIEMPRE es 2D (si la regla global fuese `barcode`, el admin lo advierte).
 */
final readonly class LiteZplLayout implements ZplLayout
{
    use SanitizesZplText;

    public function profile(): LabelSizeProfile
    {
        return LabelSizeProfile::Lite;
    }

    public function render(
        SignedLabel $label,
        LabelSymbology $symbology,
        int $dpi,
        ?string $productName,
    ): string {
        $payload = $label->payload;
        $profile = $this->profile();

        $width = $profile->widthDots($dpi);
        $height = $profile->heightDots($dpi);

        // Magnificación del QR: más alta a 300 dpi para no achicar el módulo por debajo de lo legible.
        $magnification = $dpi >= 300 ? 5 : 4;

        // El nombre completa la regla «nombre + SKU»; si falta, se usa el SKU para no dejarlo vacío.
        $name = $this->sanitizeName($productName, 28);
        if ($name === '') {
            $name = $payload->sku;
        }

        $unit = "U {$payload->unit->index}/{$payload->unit->total}";

        $textX = (int) round($width * 0.38);
        $fieldWidth = max(1, $width - $textX - (int) round($width * 0.03));

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.$width,
            '^LL'.$height,
            '^LH0,0',
            // Calibración de medio corto (gap) y velocidad/oscuridad moderadas: módulos chicos más definidos.
            '^MNY',
            '^PR2',
            '^MD4',
            // QR con el label_id desnudo: ECC Q (tolera arrugas/rayones), entrada automática.
            '^FO'.(int) round($width * 0.03).','.(int) round($height * 0.08).'^BQN,2,'.$magnification.'^FDQA,'.$payload->id.'^FS',
            // SKU (primera línea de texto, destacado).
            '^FO'.$textX.','.(int) round($height * 0.08).'^A0N,'.(int) round($height * 0.16).','.(int) round($height * 0.13).'^FB'.$fieldWidth.',1,0,L^FD'.$payload->sku.'^FS',
            // Nombre del producto (hasta dos líneas).
            '^FO'.$textX.','.(int) round($height * 0.28).'^A0N,'.(int) round($height * 0.11).','.(int) round($height * 0.09).'^FB'.$fieldWidth.',2,0,L^FD'.$name.'^FS',
            // Unidad N/M del producto.
            '^FO'.$textX.','.(int) round($height * 0.56).'^A0N,'.(int) round($height * 0.14).','.(int) round($height * 0.12).'^FD'.$unit.'^FS',
            // label_id completo legible al pie (respaldo de carga manual).
            '^FO'.(int) round($width * 0.03).','.(int) round($height * 0.84).'^A0N,'.(int) round($height * 0.1).','.(int) round($height * 0.07).'^FD'.$payload->id.'^FS',
            '^XZ',
        ];

        return implode("\n", $lines)."\n";
    }
}
