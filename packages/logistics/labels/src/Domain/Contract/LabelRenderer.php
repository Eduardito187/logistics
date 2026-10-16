<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Convierte una etiqueta firmada en el formato que entiende la impresora.
 *
 * El dominio define el puerto; la infraestructura decide el lenguaje concreto (ZPL para Zebra).
 * Devuelve texto plano listo para enviar a la impresora, nunca una imagen: la app manda el string
 * tal cual al equipo térmico.
 *
 * La simbología (QR, código de barras o ambos) es una regla configurable: quien renderiza la resuelve
 * y la pasa. El QR, cuando se dibuja, lleva siempre el mismo JSON firmado byte a byte.
 */
interface LabelRenderer
{
    public function render(SignedLabel $label, LabelSymbology $symbology = LabelSymbology::Both): string;
}
