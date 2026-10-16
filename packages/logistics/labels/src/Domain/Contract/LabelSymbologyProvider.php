<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use DateTimeImmutable;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;

/**
 * Puerto que resuelve la simbología vigente de la etiqueta (QR, código de barras o ambos).
 *
 * El dominio define el puerto; la infraestructura decide de dónde sale el valor (regla versionada en
 * `logistics/settings`, con caída a la config del package). Se consulta a una fecha para respetar el
 * versionado por `effective_from` del resto de las reglas configurables (docs/architecture, principio 5).
 */
interface LabelSymbologyProvider
{
    public function current(DateTimeImmutable $at): LabelSymbology;
}
