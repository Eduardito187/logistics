<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

use Logistics\Labels\Domain\Contract\LabelRenderer;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Renderer ZPL para Zebra. Ya NO tiene un layout fijo: elige el {@see ZplLayout} del PERFIL de tamaño y le
 * delega el dibujo. Con los valores por defecto (Both, Standard, 203 dpi) produce byte a byte el formato
 * histórico de 4", para no romper a los llamadores que no pasan perfil (factory de datos de prueba, etc.).
 *
 * Produce un string ZPL (no una imagen): la app lo manda tal cual a la impresora. El contenido del código
 * 2D depende del perfil:
 *  - Standard: QR con el JSON firmado byte a byte (contrato con la app) + Code128 opcional;
 *  - Lite/Lite40x30/Micro: el `label_id` desnudo en QR o Data Matrix —el mismo valor que ya viaja hoy en el
 *    Code128—, así que los perfiles chicos NO cambian el contrato del QR firmado (roadmap §4.3).
 *
 * El JSON firmado y el `label_id` nunca contienen `^` ni `~` (prefijos de control ZPL): base64url usa
 * `-`/`_`, el id es un ULID (A-Z0-9), y los patrones de SKU/pedido/nodo los prohíben. El nombre del
 * producto, que SÍ es texto libre, lo sanea cada layout antes de embeberlo.
 */
final readonly class ZplLabelRenderer implements LabelRenderer
{
    /** @var array<string, ZplLayout> indexado por el valor del perfil */
    private array $layouts;

    public function __construct()
    {
        $layouts = [
            new StandardZplLayout,
            new LiteZplLayout,
            new CompactDataMatrixZplLayout(LabelSizeProfile::Lite40x30),
            new CompactDataMatrixZplLayout(LabelSizeProfile::Micro),
        ];

        /** @var array<string, ZplLayout> $indexed */
        $indexed = [];
        foreach ($layouts as $layout) {
            $indexed[$layout->profile()->value] = $layout;
        }
        $this->layouts = $indexed;
    }

    public function render(
        SignedLabel $label,
        LabelSymbology $symbology = LabelSymbology::Both,
        LabelSizeProfile $profile = LabelSizeProfile::Standard,
        int $dpi = 203,
        ?string $productName = null,
    ): string {
        // Cada perfil tiene su layout registrado; el Standard es el fallback defensivo.
        $layout = $this->layouts[$profile->value] ?? $this->layouts[LabelSizeProfile::Standard->value];

        return $layout->render($label, $symbology, $dpi, $productName);
    }
}
