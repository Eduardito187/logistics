<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Service;

/**
 * Resultado de la política de tercerización ({@see OutsourcingDecision}): quién mueve el envío y, si es un
 * tercero, con qué transportadora y por qué almacén de handoff.
 *
 * `mode` es 'own_fleet' | 'outsourced' (el modo a nivel ENVÍO, el que entiende el planificador de la red);
 * no confundir con el `haulage_mode` de cada tramo, que la red deriva a 'own_fleet'/'third_party'.
 */
final readonly class OutsourcingChoice
{
    private function __construct(
        public string $mode,
        public ?string $carrierId,
        public ?string $carrierHubNodeId,
    ) {}

    /** Flota propia: el caso por defecto cuando el departamento destino no está marcado como tercerizado. */
    public static function ownFleet(): self
    {
        return new self('own_fleet', null, null);
    }

    /** Tercerizado con una transportadora (y, opcional, su almacén de handoff ya elegido). */
    public static function outsourced(string $carrierId, ?string $carrierHubNodeId = null): self
    {
        return new self('outsourced', $carrierId, $carrierHubNodeId);
    }

    public function isOutsourced(): bool
    {
        return $this->mode === 'outsourced';
    }
}
