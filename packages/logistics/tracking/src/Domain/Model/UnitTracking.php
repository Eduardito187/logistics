<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Model;

use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Tracking\Domain\Event\UnitStatusChanged;
use Logistics\Tracking\Domain\Event\UnitTrackingOpened;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

/**
 * Agregado raíz del seguimiento de un BULTO (unidad física) dentro de un envío.
 *
 * Es una proyección per-bulto del flujo de eventos de execution (preparado, cargado, descargado,
 * recibido) más el desenlace heredado del envío (entregado/fallido). Se modela como agregado propio
 * para poder evolucionar sus reglas: no retrocede desde un estado terminal, no graba eventos
 * redundantes y descarta reportes fuera de orden por su instante (lastSeenAt monótono). Referencia al
 * envío y al bulto por id string, nunca importando el agregado de otro contexto.
 */
final class UnitTracking extends AggregateRoot
{
    private function __construct(
        private readonly UnitTrackingId $id,
        private readonly string $shipmentId,
        private readonly string $unitId,
        private UnitStatus $status,
        private ?string $lastLocationId,
        private ?DateTimeImmutable $lastSeenAt,
    ) {}

    /**
     * Abre el seguimiento de un bulto en estado Registered y graba UnitTrackingOpened.
     */
    public static function open(
        UnitTrackingId $id,
        string $shipmentId,
        string $unitId,
    ): self {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw TrackingException::because('el id del envío no puede estar vacío');
        }
        $unitId = trim($unitId);
        if ($unitId === '') {
            throw TrackingException::because('el id del bulto no puede estar vacío');
        }

        $unit = new self($id, $shipmentId, $unitId, UnitStatus::Registered, null, null);

        $unit->recordEvent(new UnitTrackingOpened(
            $id->toString(),
            $shipmentId,
            $unitId,
            UnitStatus::Registered->value,
        ));

        return $unit;
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     */
    public static function reconstitute(
        UnitTrackingId $id,
        string $shipmentId,
        string $unitId,
        UnitStatus $status,
        ?string $lastLocationId,
        ?DateTimeImmutable $lastSeenAt,
    ): self {
        return new self($id, $shipmentId, $unitId, $status, $lastLocationId, $lastSeenAt);
    }

    /**
     * Aplica un nuevo estado al bulto a partir de un evento de ejecución.
     *
     * - Descarta reportes fuera de orden: si el evento es anterior al último visto, no hace nada (la
     *   proyección es monótona en el tiempo, tolerante a reentregas y a relays desordenados).
     * - No retrocede desde un estado terminal (entregado/fallido): el bulto ya cerró.
     * - Idempotente: si el estado y la ubicación no cambian, no graba evento.
     *
     * Devuelve true si el estado visible cambió (grabó UnitStatusChanged), false si fue un no-op o solo
     * actualizó ubicación/instante.
     */
    public function applyStatus(UnitStatus $status, ?string $locationId, ?DateTimeImmutable $seenAt): bool
    {
        // Reporte fuera de orden: llega un evento anterior al último ya proyectado. Se ignora.
        if ($seenAt !== null && $this->lastSeenAt !== null && $seenAt < $this->lastSeenAt) {
            return false;
        }

        if ($this->status->isTerminal()) {
            // El bulto ya cerró con el desenlace del envío; los escaneos posteriores no lo mueven.
            return false;
        }

        if ($seenAt !== null) {
            $this->lastSeenAt = $seenAt;
        }

        $locationId = $locationId !== null ? trim($locationId) : null;
        if ($locationId !== null && $locationId !== '') {
            $this->lastLocationId = $locationId;
        }

        if ($this->status === $status) {
            return false;
        }

        $previous = $this->status;
        $this->status = $status;

        $this->recordEvent(new UnitStatusChanged(
            $this->id->toString(),
            $this->shipmentId,
            $this->unitId,
            $previous->value,
            $status->value,
        ));

        return true;
    }

    public function id(): UnitTrackingId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function status(): UnitStatus
    {
        return $this->status;
    }

    public function lastLocationId(): ?string
    {
        return $this->lastLocationId;
    }

    public function lastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
