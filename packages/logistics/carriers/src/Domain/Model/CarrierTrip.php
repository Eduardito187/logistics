<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Carriers\Domain\Event\CarrierTripCancelled;
use Logistics\Carriers\Domain\Event\CarrierTripClosed;
use Logistics\Carriers\Domain\Event\CarrierTripScheduled;
use Logistics\Carriers\Domain\Event\CarrierTripShipmentRemoved;
use Logistics\Carriers\Domain\Event\CarrierTripShipmentsAssigned;
use Logistics\Carriers\Domain\Event\CarrierTripStarted;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Viaje de un transportista tercero: la CARGA asignada (envíos) que recoge en el origen de un corredor y
 * lleva al destino (auditoría app, pedido #4: antes el transportista escaneaba a ciegas).
 *
 * Ciclo de vida: la oficina lo programa (Scheduled) y arma la carga (agregar/quitar envíos); el
 * transportista lo inicia al recoger (InProgress) y lo cierra al entregar (Closed). Un programado puede
 * cancelarse (Cancelled). Iniciar y cerrar son IDEMPOTENTES: repetir la misma transición sobre un viaje
 * que ya está en ese estado no cambia nada ni graba eventos (reintentos offline de la app). Cualquier otra
 * transición (cerrar uno programado, iniciar uno cerrado) es una regla de negocio violada.
 *
 * Referencia al transportista, a los nodos y a los envíos por id como texto: este contexto no importa
 * los agregados de carriers.Carrier, network ni shipments (contexto extraíble a servicio).
 */
final class CarrierTrip extends AggregateRoot
{
    /**
     * @param list<string> $shipmentIds
     */
    private function __construct(
        private readonly CarrierTripId $id,
        private readonly string $carrierId,
        private readonly string $corridorFrom,
        private readonly string $corridorTo,
        private DateTimeImmutable $scheduledAt,
        private readonly ?string $reference,
        private CarrierTripStatus $status,
        private array $shipmentIds,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $closedAt,
        private ?DateTimeImmutable $cancelledAt,
        private ?string $startedBy,
        private ?string $closedBy,
    ) {}

    /**
     * La oficina programa un viaje nuevo para un transportista, opcionalmente con su carga inicial.
     *
     * @param list<string> $shipmentIds
     */
    public static function schedule(
        CarrierTripId $id,
        string $carrierId,
        string $corridorFrom,
        string $corridorTo,
        DateTimeImmutable $scheduledAt,
        ?string $reference = null,
        array $shipmentIds = [],
    ): self {
        $carrierId = trim($carrierId);
        $corridorFrom = trim($corridorFrom);
        $corridorTo = trim($corridorTo);
        $reference = $reference !== null && trim($reference) !== '' ? trim($reference) : null;

        if ($carrierId === '') {
            throw CarriersException::because('El viaje requiere un transportista.');
        }
        if ($corridorFrom === '' || $corridorTo === '') {
            throw CarriersException::because('El viaje requiere origen y destino del corredor.');
        }
        if ($corridorFrom === $corridorTo) {
            throw CarriersException::because('El origen y el destino del viaje deben ser distintos.');
        }

        $shipments = self::normalizeIds($shipmentIds);

        $trip = new self(
            $id,
            $carrierId,
            $corridorFrom,
            $corridorTo,
            $scheduledAt,
            $reference,
            CarrierTripStatus::Scheduled,
            $shipments,
            null,
            null,
            null,
            null,
            null,
        );
        $trip->recordEvent(new CarrierTripScheduled(
            $id->toString(),
            $carrierId,
            $corridorFrom,
            $corridorTo,
            $scheduledAt->format(DateTimeInterface::ATOM),
            $reference,
            $shipments,
        ));

        return $trip;
    }

    /**
     * Reconstituye un viaje desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<string> $shipmentIds
     */
    public static function reconstitute(
        CarrierTripId $id,
        string $carrierId,
        string $corridorFrom,
        string $corridorTo,
        DateTimeImmutable $scheduledAt,
        ?string $reference,
        CarrierTripStatus $status,
        array $shipmentIds,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $closedAt,
        ?DateTimeImmutable $cancelledAt,
        ?string $startedBy,
        ?string $closedBy,
    ): self {
        return new self(
            $id,
            $carrierId,
            $corridorFrom,
            $corridorTo,
            $scheduledAt,
            $reference,
            $status,
            array_values($shipmentIds),
            $startedAt,
            $closedAt,
            $cancelledAt,
            $startedBy,
            $closedBy,
        );
    }

    /**
     * Agrega envíos a la carga. Solo mientras el viaje está programado (la carga en ruta no se toca).
     * Los envíos ya presentes se ignoran. Devuelve los ids efectivamente agregados.
     *
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    public function assignShipments(array $shipmentIds): array
    {
        $this->assertScheduled('agregar envíos a');

        $added = array_values(array_diff(self::normalizeIds($shipmentIds), $this->shipmentIds));
        if ($added === []) {
            return [];
        }

        $this->shipmentIds = [...$this->shipmentIds, ...$added];
        $this->recordEvent(new CarrierTripShipmentsAssigned($this->id->toString(), $this->carrierId, $added));

        return $added;
    }

    /**
     * Quita un envío de la carga. Solo mientras el viaje está programado. Quitar uno ausente no hace nada.
     */
    public function removeShipment(string $shipmentId): void
    {
        $this->assertScheduled('quitar envíos de');

        $shipmentId = strtolower(trim($shipmentId));
        if (!in_array($shipmentId, $this->shipmentIds, true)) {
            return;
        }

        $this->shipmentIds = array_values(array_filter(
            $this->shipmentIds,
            static fn (string $id): bool => $id !== $shipmentId,
        ));
        $this->recordEvent(new CarrierTripShipmentRemoved($this->id->toString(), $this->carrierId, $shipmentId));
    }

    /**
     * Reprograma la fecha de salida. Solo mientras está programado.
     */
    public function reschedule(DateTimeImmutable $scheduledAt): void
    {
        $this->assertScheduled('reprogramar');
        $this->scheduledAt = $scheduledAt;
    }

    /**
     * Inicia el viaje (la carga sale del origen). Idempotente: si ya está en ruta devuelve false sin
     * cambios. Requiere carga asignada. Un viaje cerrado o cancelado no se puede iniciar.
     */
    public function start(DateTimeImmutable $now, ?string $actorId = null): bool
    {
        if ($this->status === CarrierTripStatus::InProgress) {
            return false;
        }
        if ($this->status !== CarrierTripStatus::Scheduled) {
            throw CarriersException::because("No se puede iniciar un viaje en estado {$this->status->value}.");
        }
        if ($this->shipmentIds === []) {
            throw CarriersException::because('No se puede iniciar un viaje sin envíos asignados.');
        }

        $this->status = CarrierTripStatus::InProgress;
        $this->startedAt = $now;
        $this->startedBy = $actorId;
        $this->recordEvent(new CarrierTripStarted(
            $this->id->toString(),
            $this->carrierId,
            $this->corridorFrom,
            $this->corridorTo,
            $now->format(DateTimeInterface::ATOM),
            $actorId,
            $this->shipmentIds,
        ));

        return true;
    }

    /**
     * Cierra el viaje (la carga llegó a destino). Idempotente: si ya está cerrado devuelve false sin
     * cambios. Solo se cierra un viaje en ruta.
     */
    public function close(DateTimeImmutable $now, ?string $actorId = null): bool
    {
        if ($this->status === CarrierTripStatus::Closed) {
            return false;
        }
        if ($this->status !== CarrierTripStatus::InProgress) {
            throw CarriersException::because(
                "Solo se puede cerrar un viaje en ruta; está en {$this->status->value}.",
            );
        }

        $this->status = CarrierTripStatus::Closed;
        $this->closedAt = $now;
        $this->closedBy = $actorId;
        $this->recordEvent(new CarrierTripClosed(
            $this->id->toString(),
            $this->carrierId,
            $this->corridorTo,
            $now->format(DateTimeInterface::ATOM),
            $actorId,
            $this->shipmentIds,
        ));

        return true;
    }

    /**
     * Cancela un viaje programado. Idempotente sobre uno ya cancelado; no se cancela uno en ruta o cerrado.
     */
    public function cancel(DateTimeImmutable $now, ?string $reason = null): bool
    {
        if ($this->status === CarrierTripStatus::Cancelled) {
            return false;
        }
        $this->assertScheduled('cancelar');

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $this->status = CarrierTripStatus::Cancelled;
        $this->cancelledAt = $now;
        $this->recordEvent(new CarrierTripCancelled(
            $this->id->toString(),
            $this->carrierId,
            $now->format(DateTimeInterface::ATOM),
            $reason,
            $this->shipmentIds,
        ));

        return true;
    }

    public function id(): CarrierTripId
    {
        return $this->id;
    }

    public function carrierId(): string
    {
        return $this->carrierId;
    }

    public function corridorFrom(): string
    {
        return $this->corridorFrom;
    }

    public function corridorTo(): string
    {
        return $this->corridorTo;
    }

    public function scheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function status(): CarrierTripStatus
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function shipmentIds(): array
    {
        return $this->shipmentIds;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function startedBy(): ?string
    {
        return $this->startedBy;
    }

    public function closedBy(): ?string
    {
        return $this->closedBy;
    }

    private function assertScheduled(string $action): void
    {
        if ($this->status !== CarrierTripStatus::Scheduled) {
            throw CarriersException::because(
                "No se puede {$action} un viaje en estado {$this->status->value}; solo si está programado.",
            );
        }
    }

    /**
     * Normaliza ids de envío: recorta, pasa a minúsculas, descarta vacíos y duplicados (orden estable).
     *
     * @param  list<string> $ids
     * @return list<string>
     */
    private static function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = strtolower(trim($id));
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
