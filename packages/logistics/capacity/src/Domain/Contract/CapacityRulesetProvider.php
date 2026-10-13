<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use DateTimeImmutable;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;

/**
 * Puerto de las reglas de cupo versionadas. El dominio pide "las reglas vigentes a esta fecha" y recibe
 * un CapacityRuleset ya interpretado; de dónde salgan (settings versionado o la config del package) es
 * cosa de la implementación en Infrastructure.
 *
 * Se separa de CapacityRuleProvider —que resuelve el total por defecto de un bucket concreto— porque
 * este puerto entrega el conjunto completo de reglas para planificar el calendario.
 */
interface CapacityRulesetProvider
{
    /**
     * Reglas de cupo vigentes a la fecha dada. Siempre devuelve un conjunto válido: si no hay versión
     * publicada en settings, cae a los valores por defecto de la configuración del package.
     */
    public function current(DateTimeImmutable $at): CapacityRuleset;
}
