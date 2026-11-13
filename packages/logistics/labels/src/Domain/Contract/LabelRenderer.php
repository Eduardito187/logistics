<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Convierte una etiqueta firmada en el formato que entiende la impresora.
 *
 * El dominio define el puerto; la infraestructura decide el lenguaje concreto (ZPL para Zebra).
 * Devuelve texto plano listo para enviar a la impresora, nunca una imagen: la app manda el string
 * tal cual al equipo térmico.
 *
 * La simbología (QR, código de barras o ambos) y el PERFIL de tamaño son reglas configurables: quien
 * renderiza las resuelve y las pasa. Con los valores por defecto (Both, Standard, 203 dpi) el resultado es
 * byte a byte el formato histórico de 4", para no romper a nadie que llame sin esos argumentos.
 *
 * El perfil Standard dibuja el QR con el JSON firmado byte a byte. Los perfiles chicos (Lite/Micro) llevan
 * el `label_id` DESNUDO en el código 2D —el mismo valor que ya viaja hoy en el Code128— así que NO cambian
 * el contrato del QR firmado con la app (roadmap §4.3). El nombre del producto, cuando se conoce, se pasa
 * aparte: no viaja en el payload firmado (ADR 004).
 */
interface LabelRenderer
{
    public function render(
        SignedLabel $label,
        LabelSymbology $symbology = LabelSymbology::Both,
        LabelSizeProfile $profile = LabelSizeProfile::Standard,
        int $dpi = 203,
        ?string $productName = null,
    ): string;
}
