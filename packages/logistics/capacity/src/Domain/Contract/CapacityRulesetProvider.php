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

    /**
     * Reglas de cupo vigentes para un municipio a la fecha dada (calendario de 7 días por municipio). Si
     * el municipio no tiene reglas propias publicadas, cae a las reglas de la red (current). Con municipio
     * null es equivalente a current(): la configuración de la red.
     */
    public function currentForMunicipality(?string $municipalityId, DateTimeImmutable $at): CapacityRuleset;
}
