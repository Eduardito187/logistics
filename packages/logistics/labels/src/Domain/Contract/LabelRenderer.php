<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Convierte una etiqueta firmada en el formato que entiende la impresora.
 *
 * El dominio define el puerto; la infraestructura decide el lenguaje concreto (ZPL para Zebra).
 * Devuelve texto plano listo para enviar a la impresora, nunca una imagen: la app manda el string
 * tal cual al equipo térmico.
 */
interface LabelRenderer
{
    public function render(SignedLabel $label): string;
}
