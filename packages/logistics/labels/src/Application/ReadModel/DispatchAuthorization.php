<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

/**
 * Resultado de preguntar si un envío está AUTORIZADO a despacharse (etiquetar y preparar).
 *
 * Lleva, cuando no lo está, el motivo legible (`reason`, el texto histórico que ya muestra la app) y un
 * código estable (`reasonCode`) para que la app decida la acción sin comparar textos:
 *
 *  - `not_assigned`  → el envío no tiene camión por ninguna vía: se destraba ASIGNANDO EL VEHÍCULO al
 *                      pedido (o publicando un plan que lo incluya).
 *  - `vehicle_unfit` → tiene camión, pero ese camión quedó NO APTO (ítem crítico del pre-viaje fallado y
 *                      sin liberar): se destraba reasignando el pedido a otro camión o liberando el vehículo.
 */
final readonly class DispatchAuthorization
{
    /** Sin camión por ninguna vía (ni plan publicado, ni entrega abierta, ni parada en una ruta). */
    public const string CODE_NOT_ASSIGNED = 'not_assigned';

    /** El camión asignado está marcado como no apto para salir. */
    public const string CODE_VEHICLE_UNFIT = 'vehicle_unfit';

    /** Texto histórico del contrato con la app: se conserva tal cual para no romper pantallas existentes. */
    public const string REASON_NOT_ASSIGNED = 'sin plan publicado';

    public const string REASON_VEHICLE_UNFIT = 'vehículo no apto';

    private function __construct(
        public bool $authorized,
        public ?string $reason,
        public ?string $reasonCode,
    ) {}

    public static function granted(): self
    {
        return new self(true, null, null);
    }

    public static function notAssigned(): self
    {
        return new self(false, self::REASON_NOT_ASSIGNED, self::CODE_NOT_ASSIGNED);
    }

    public static function vehicleUnfit(): self
    {
        return new self(false, self::REASON_VEHICLE_UNFIT, self::CODE_VEHICLE_UNFIT);
    }
}
