<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Settings;

use Illuminate\Contracts\Config\Repository;
use Logistics\Capacity\Domain\Contract\CapacityRuleProvider;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Implementación del contrato de reglas de cupo apoyada en la configuración del package
 * (`config/logistics-capacity.php`). Es el stub previsto por el README: deja el punto de extensión
 * funcional hasta que `logistics/settings` sirva las reglas versionadas; ese día se cambia solo el
 * binding del provider, sin tocar el dominio ni los handlers que lo consumen.
 *
 * Devuelve el total por defecto de una unidad (afinado por franja si hay regla específica), o null si
 * la configuración no define nada para esa unidad (entonces el cupo debe definirse explícitamente).
 * El scope no altera el total por defecto todavía; se recibe para respetar el contrato y poder
 * discriminar por nodo/zona cuando las reglas lo soporten.
 */
final readonly class ConfiguredCapacityRuleProvider implements CapacityRuleProvider
{
    public function __construct(private Repository $config) {}

    public function defaultTotalFor(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?Quantity {
        // Afinamiento por franja: gana sobre el total por unidad si existe.
        $bySlot = $this->config->get("logistics-capacity.by_slot.{$unit->value}.{$slot->toString()}");
        if (is_numeric($bySlot)) {
            return Quantity::of((float) $bySlot);
        }

        $total = $this->config->get("logistics-capacity.default_totals.{$unit->value}");

        return is_numeric($total) ? Quantity::of((float) $total) : null;
    }
}
