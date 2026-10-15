<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateInterval;
use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\MaintenancePlanActivated;
use Logistics\FleetOps\Domain\Event\MaintenancePlanDeactivated;
use Logistics\FleetOps\Domain\Event\MaintenancePlanDefined;
use Logistics\FleetOps\Domain\Event\MaintenancePlanServiced;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Plan de mantenimiento preventivo de un vehículo: vigila un tipo de servicio (ServiceKind) por km y/o
 * por días. Al registrar que el servicio se hizo, fija el último servicio y recalcula el próximo
 * vencimiento (next_due_km = last + interval_km; next_due_at = fecha + interval_days). `isDue()` decide
 * si está vencido o por vencer según los umbrales de aviso. Referencia al vehículo por id como texto.
 */
final class MaintenancePlan extends AggregateRoot
{
    private function __construct(
        private readonly MaintenancePlanId $id,
        private readonly string $vehicleId,
        private readonly ServiceKind $serviceKind,
        private readonly ?int $intervalKm,
        private readonly ?int $intervalDays,
        private ?int $lastDoneKm,
        private ?DateTimeImmutable $lastDoneAt,
        private ?int $nextDueKm,
        private ?DateTimeImmutable $nextDueAt,
        private bool $active,
    ) {}

    /**
     * Define un plan nuevo. Requiere al menos un intervalo (km o días). Arranca activo y sin servicios.
     */
    public static function define(
        MaintenancePlanId $id,
        string $vehicleId,
        ServiceKind $serviceKind,
        ?int $intervalKm,
        ?int $intervalDays,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw FleetOpsException::because('El plan de mantenimiento requiere un vehículo.');
        }
        if ($intervalKm === null && $intervalDays === null) {
            throw FleetOpsException::because('El plan requiere al menos un intervalo (km o días).');
        }
        if ($intervalKm !== null && $intervalKm <= 0) {
            throw FleetOpsException::because("El intervalo en km debe ser mayor que cero: {$intervalKm}.");
        }
        if ($intervalDays !== null && $intervalDays <= 0) {
            throw FleetOpsException::because("El intervalo en días debe ser mayor que cero: {$intervalDays}.");
        }

        $plan = new self($id, $vehicleId, $serviceKind, $intervalKm, $intervalDays, null, null, null, null, true);
        $plan->recordEvent(new MaintenancePlanDefined(
            $id->toString(),
            $vehicleId,
            $serviceKind->value,
            $intervalKm,
            $intervalDays,
        ));

        return $plan;
    }

    /**
     * Reconstituye un plan desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        MaintenancePlanId $id,
        string $vehicleId,
        ServiceKind $serviceKind,
        ?int $intervalKm,
        ?int $intervalDays,
        ?int $lastDoneKm,
        ?DateTimeImmutable $lastDoneAt,
        ?int $nextDueKm,
        ?DateTimeImmutable $nextDueAt,
        bool $active,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $serviceKind,
            $intervalKm,
            $intervalDays,
            $lastDoneKm,
            $lastDoneAt,
            $nextDueKm,
            $nextDueAt,
            $active,
        );
    }

    /**
     * Registra que el servicio se ejecutó: fija el último servicio y recalcula el próximo vencimiento.
     * El próximo vencimiento por km solo se calcula si hay odómetro y hay intervalo en km.
     */
    public function recordServiceDone(?Odometer $odometer, DateTimeImmutable $at): void
    {
        $this->lastDoneKm = $odometer?->km;
        $this->lastDoneAt = $at;

        $this->nextDueKm = ($this->intervalKm !== null && $odometer !== null)
            ? $odometer->km + $this->intervalKm
            : null;

        $this->nextDueAt = $this->intervalDays !== null
            ? $at->add(new DateInterval("P{$this->intervalDays}D"))
            : null;

        $this->recordEvent(new MaintenancePlanServiced(
            $this->id->toString(),
            $this->lastDoneKm,
            $at->format(DateTimeImmutable::ATOM),
            $this->nextDueKm,
            $this->nextDueAt?->format(DateTimeImmutable::ATOM),
        ));
    }

    /**
     * ¿El plan está vencido o por vencer? Un plan inactivo nunca está vencido. Cubre dos dimensiones:
     * por km (odómetro actual alcanzó el vencimiento menos el umbral de aviso) y por fecha (hoy alcanzó
     * el vencimiento menos los días de aviso). Basta con que una de las dos se cumpla.
     */
    public function isDue(?Odometer $currentKm, DateTimeImmutable $now, int $dueSoonKm, int $dueSoonDays): bool
    {
        if (!$this->active) {
            return false;
        }

        if ($this->nextDueKm !== null && $currentKm !== null && $currentKm->km >= ($this->nextDueKm - $dueSoonKm)) {
            return true;
        }

        if ($this->nextDueAt !== null) {
            $threshold = $this->nextDueAt->sub(new DateInterval("P{$dueSoonDays}D"));
            if ($now >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Activa el plan. Idempotente: si ya está activo, no hace nada ni graba evento.
     */
    public function activate(): void
    {
        if ($this->active) {
            return;
        }
        $this->active = true;
        $this->recordEvent(new MaintenancePlanActivated($this->id->toString()));
    }

    /**
     * Desactiva el plan. Idempotente: si ya está inactivo, no hace nada ni graba evento.
     */
    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->recordEvent(new MaintenancePlanDeactivated($this->id->toString()));
    }

    public function id(): MaintenancePlanId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function serviceKind(): ServiceKind
    {
        return $this->serviceKind;
    }

    public function intervalKm(): ?int
    {
        return $this->intervalKm;
    }

    public function intervalDays(): ?int
    {
        return $this->intervalDays;
    }

    public function lastDoneKm(): ?int
    {
        return $this->lastDoneKm;
    }

    public function lastDoneAt(): ?DateTimeImmutable
    {
        return $this->lastDoneAt;
    }

    public function nextDueKm(): ?int
    {
        return $this->nextDueKm;
    }

    public function nextDueAt(): ?DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
