<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La entrega se completó con prueba de entrega (QR del cliente o firma, y opcionalmente foto).
 * De acá se alimentan tracking y notifications.
 *
 * Un traspaso interno también lo emite al quedar en su nodo destino (entrega en tienda), sin prueba de
 * entrega: `confirmation` vale `STORE_DROP` y no lleva foto.
 *
 * Cierre CON FALTANTES (solo traspasos): si la tienda concilió la recepción con diferencias, o la oficina
 * cerró el traspaso a mano, `withShortage` es verdadero y `missingUnitIds` lista los productos que no
 * llegaron; `unitIds` trae solo los entregados. Es aditivo: quien no lo lea sigue viendo una entrega normal.
 */
final readonly class ShipmentDelivered extends BaseDomainEvent
{
    /**
     * @param list<string> $unitIds        bultos entregados con esta prueba (POD en lote)
     * @param list<string> $evidenceIds    evidencias anti-fraude vinculadas (firma, documento)
     * @param list<string> $missingUnitIds productos que quedaron como faltantes (cierre de traspaso con faltantes)
     */
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $confirmation,
        public ?string $photoUrl,
        DateTimeImmutable $occurredAt,
        public array $unitIds = [],
        public array $evidenceIds = [],
        public bool $withShortage = false,
        public array $missingUnitIds = [],
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_delivered';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'confirmation' => $this->confirmation,
            'photo_url' => $this->photoUrl,
            // Hora real de la entrega (capturas offline incluidas): la usan tracking (delivered_at) y el aviso a SAP.
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            // Aditivo: bultos entregados y evidencias vinculadas (POD en lote).
            'unit_ids' => $this->unitIds,
            'units_delivered' => count($this->unitIds),
            'evidence_ids' => $this->evidenceIds,
            // Aditivo: traspaso cerrado con faltantes (conciliación con diferencias o cierre manual de oficina).
            'with_shortage' => $this->withShortage,
            'missing_unit_ids' => $this->missingUnitIds,
        ];
    }
}
