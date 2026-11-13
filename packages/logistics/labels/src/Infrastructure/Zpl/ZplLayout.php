<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Un layout ZPL concreto para un perfil de tamaño: sabe dibujar la etiqueta en sus medidas, con la
 * posición del código 2D y los textos legibles propios de ese perfil. El {@see ZplLabelRenderer} elige el
 * layout por perfil y le delega el dibujo.
 *
 * `$productName` es el nombre del producto (dato del pedido, NO viaja en el QR firmado); se pasa aparte
 * para cumplir la regla «nombre + SKU» en los perfiles que lo muestran. El layout lo sanea e ignora cuando
 * su perfil no lo dibuja.
 */
interface ZplLayout
{
    public function profile(): LabelSizeProfile;

    public function render(
        SignedLabel $label,
        LabelSymbology $symbology,
        int $dpi,
        ?string $productName,
    ): string;
}
