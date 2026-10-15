<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\MaintenanceCancelled;
use Logistics\FleetOps\Domain\Event\MaintenanceCompleted;
use Logistics\FleetOps\Domain\Event\MaintenanceScheduled;
use Logistics\FleetOps\Domain\Event\MaintenanceStarted;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceItem;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Orden de mantenimiento de un vehículo de la flota propia (preventivo, correctivo o inspección).
 *
 * Ciclo de vida: Scheduled → InProgress → Completed, con Cancelled como salida desde Scheduled o
 * InProgress. El agregado protege las transiciones (no se completa una cancelada, no se cancela una ya
 * completada) y, al completarse, fija sus ítems y calcula el costo total sumando los `lineTotal()`.
 * Referencia al vehículo por su id como texto (UUID de fleet) para no acoplarse a ese contexto.
 */
final class MaintenanceOrder extends AggregateRoot
{
    /**
     * @param list<MaintenanceItem> $items
     */
    private function __construct(
        private readonly MaintenanceOrderId $id,
        private readonly string $vehicleId,
        private readonly MaintenanceType $type,
        private MaintenanceStatus $status,
        private readonly ?Odometer $odometer,
        private readonly ?string $workshop,
        private array $items,
        private Money $totalCost,
        private readonly ?DateTimeImmutable $scheduledAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $completedAt,
        private readonly ?string $note,
    ) {}

    /**
     * Agenda una orden de mantenimiento nueva. Arranca en Scheduled, sin ítems y con costo cero.
     */
    public static function schedule(
        MaintenanceOrderId $id,
        string $vehicleId,
        MaintenanceType $type,
        ?Odometer $odometer,
        ?string $workshop,
        DateTimeImmutable $scheduledAt,
        ?string $note,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw FleetOpsException::because('La orden de mantenimiento requiere un vehículo.');
        }

        $order = new self(
            $id,
            $vehicleId,
            $type,
            MaintenanceStatus::Scheduled,
            $odometer,
            $workshop,
            [],
            Money::zero(),
            $scheduledAt,
            null,
            null,
            $note,
        );
        $order->recordEvent(new MaintenanceScheduled(
            $id->toString(),
            $vehicleId,
            $type->value,
            $odometer?->km,
            $scheduledAt->format(DateTimeImmutable::ATOM),
        ));

        return $order;
    }

    /**
     * Reconstituye una orden desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<MaintenanceItem> $items
     */
    public static function reconstitute(
        MaintenanceOrderId $id,
        string $vehicleId,
        MaintenanceType $type,
        MaintenanceStatus $status,
        ?Odometer $odometer,
        ?string $workshop,
        array $items,
        Money $totalCost,
        ?DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        ?string $note,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $type,
            $status,
            $odometer,
            $workshop,
            array_values($items),
            $totalCost,
            $scheduledAt,
            $startedAt,
            $completedAt,
            $note,
        );
    }

    /**
     * Inicia la orden (el vehículo entra al taller). Solo desde Scheduled.
     */
    public function start(DateTimeImmutable $at): void
    {
        if ($this->status !== MaintenanceStatus::Scheduled) {
            throw FleetOpsException::because(
                "Solo se puede iniciar una orden agendada; está en {$this->status->value}.",
            );
        }
        $this->status = MaintenanceStatus::InProgress;
        $this->startedAt = $at;
        $this->recordEvent(new MaintenanceStarted($this->id->toString(), $at->format(DateTimeImmutable::ATOM)));
    }

    /**
     * Completa la orden: fija sus ítems y calcula el costo total sumando los `lineTotal()`.
     * Solo desde Scheduled o InProgress (no se completa una cancelada ni una ya completada).
     *
     * @param list<MaintenanceItem> $items
     */
    public function complete(array $items, DateTimeImmutable $at): void
    {
        if (!in_array($this->status, [MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress], true)) {
            throw FleetOpsException::because(
                "No se puede completar una orden en estado {$this->status->value}.",
            );
        }

        $items = array_values($items);
        // La moneda del total la fija el primer ítem (todos comparten moneda); sin ítems, cero BOB.
        $total = $items === [] ? Money::zero() : Money::zero($items[0]->lineTotal()->currency);
        foreach ($items as $item) {
            $total = $total->add($item->lineTotal());
        }

        $this->items = $items;
        $this->totalCost = $total;
        $this->status = MaintenanceStatus::Completed;
        $this->completedAt = $at;

        $this->recordEvent(new MaintenanceCompleted(
            $this->id->toString(),
            $total->cents,
            $total->currency,
            $this->odometer?->km,
            $at->format(DateTimeImmutable::ATOM),
        ));
    }

    /**
     * Cancela la orden. No se puede cancelar una ya completada; es idempotente si ya está cancelada.
     */
    public function cancel(DateTimeImmutable $at): void
    {
        if ($this->status === MaintenanceStatus::Completed) {
            throw FleetOpsException::because('No se puede cancelar una orden ya completada.');
        }
        if ($this->status === MaintenanceStatus::Cancelled) {
            return;
        }
        $this->status = MaintenanceStatus::Cancelled;
        $this->recordEvent(new MaintenanceCancelled($this->id->toString(), $at->format(DateTimeImmutable::ATOM)));
    }

    public function id(): MaintenanceOrderId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function type(): MaintenanceType
    {
        return $this->type;
    }

    public function status(): MaintenanceStatus
    {
        return $this->status;
    }

    public function odometer(): ?Odometer
    {
        return $this->odometer;
    }

    public function workshop(): ?string
    {
        return $this->workshop;
    }

    /**
     * @return list<MaintenanceItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function totalCost(): Money
    {
        return $this->totalCost;
    }

    public function scheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
