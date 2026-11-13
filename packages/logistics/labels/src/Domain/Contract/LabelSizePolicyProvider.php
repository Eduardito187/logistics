<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use DateTimeImmutable;
use Logistics\Labels\Domain\ValueObject\LabelSizePolicy;

/**
 * Puerto que entrega la política de tamaño vigente a una fecha dada (perfil por defecto, reglas por SKU y
 * umbrales de medidas). La infraestructura la lee de la configuración versionada de `logistics/settings`,
 * con caída a la config del package. Mismo patrón que {@see LabelSymbologyProvider}.
 */
interface LabelSizePolicyProvider
{
    public function current(DateTimeImmutable $at): LabelSizePolicy;
}
