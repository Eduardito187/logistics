<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;

/**
 * Parada de la ruta del chofer: lo que el chofer deja (o intenta dejar) en un punto de la secuencia.
 *
 * Entidad del agregado VehicleRoute: tiene identidad propia pero solo vive dentro de la ruta. Referencia
 * al envío SOLO por id string; además lleva una foto legible del destino (pedido, cliente, dirección y
 * ETA) para que el chofer sepa qué baja en cada parada sin consultar otros contextos. La posición en la
 * secuencia es fija (viene del plan publicado); solo su estado cambia al atenderla.
 */
final class RouteStop
{
    private function __construct(
        private readonly RouteStopId $id,
        private readonly string $shipmentId,
        private readonly int $sequence,
        private readonly ?string $orderReference,
        private readonly ?string $customerName,
        private readonly ?string $address,
        private ?DateTimeImmutable $etaAt,
        private readonly ?float $lat,
        private readonly ?float $lng,
        private RouteStopStatus $status,
        private ?DateTimeImmutable $settledAt,
    ) {}

    /**
     * Crea una parada pendiente de la ruta, con su foto legible del destino.
     */
    public static function plan(
        RouteStopId $id,
        string $shipmentId,
        int $sequence,
        ?string $orderReference = null,
        ?string $customerName = null,
        ?string $address = null,
        ?DateTimeImmutable $etaAt = null,
        ?float $lat = null,
        ?float $lng = null,
    ): self {
        $trimmed = trim($shipmentId);
        if ($trimmed === '') {
            throw ExecutionException::because('la parada de la ruta requiere el id del envío');
        }
        if ($sequence < 1) {
            throw ExecutionException::because("la secuencia de parada debe ser >= 1: {$sequence}");
        }

        return new self(
            $id,
            $trimmed,
            $sequence,
            self::clean($orderReference),
            self::clean($customerName),
            self::clean($address),
            $etaAt,
            $lat,
            $lng,
            RouteStopStatus::Pending,
            null,
        );
    }

    /**
     * Reconstituye una parada desde la persistencia, con su estado ya resuelto.
     */
    public static function reconstitute(
        RouteStopId $id,
        string $shipmentId,
        int $sequence,
        ?string $orderReference,
        ?string $customerName,
        ?string $address,
        ?DateTimeImmutable $etaAt,
        ?float $lat,
        ?float $lng,
        RouteStopStatus $status,
        ?DateTimeImmutable $settledAt,
    ): self {
        return new self(
            $id,
            $shipmentId,
            $sequence,
            $orderReference,
            $customerName,
            $address,
            $etaAt,
            $lat,
            $lng,
            $status,
            $settledAt,
        );
    }

    /**
     * Marca la parada como atendida con entrega exitosa. Solo lo llama el agregado.
     */
    public function complete(DateTimeImmutable $at): void
    {
        $this->status = RouteStopStatus::Completed;
        $this->settledAt = $at;
    }

    /**
     * Marca la parada como atendida con intento fallido. Solo lo llama el agregado.
     */
    public function fail(DateTimeImmutable $at): void
    {
        $this->status = RouteStopStatus::Failed;
        $this->settledAt = $at;
    }

    /**
     * Saca la parada de esta ruta por un incidente MAYOR del camión: el envío se replanifica para otro
     * día. Solo lo llama el agregado, y solo sobre paradas pendientes. Deja constancia del momento.
     */
    public function reschedule(DateTimeImmutable $at): void
    {
        $this->status = RouteStopStatus::Rescheduled;
        $this->settledAt = $at;
    }

    /**
     * Empuja la ETA de la parada por un incidente MENOR del camión (la ruta sigue, con retraso). Solo lo
     * llama el agregado, y solo sobre paradas pendientes. Si la parada no tenía ETA, no hay nada que
     * empujar. No cambia el estado: la parada sigue pendiente de atender.
     */
    public function delayEtaBy(int $minutes): void
    {
        if ($minutes <= 0 || $this->etaAt === null) {
            return;
        }

        $this->etaAt = $this->etaAt->modify("+{$minutes} minutes");
    }

    public function isPending(): bool
    {
        return $this->status === RouteStopStatus::Pending;
    }

    public function id(): RouteStopId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function orderReference(): ?string
    {
        return $this->orderReference;
    }

    public function customerName(): ?string
    {
        return $this->customerName;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function etaAt(): ?DateTimeImmutable
    {
        return $this->etaAt;
    }

    public function lat(): ?float
    {
        return $this->lat;
    }

    public function lng(): ?float
    {
        return $this->lng;
    }

    public function status(): RouteStopStatus
    {
        return $this->status;
    }

    public function settledAt(): ?DateTimeImmutable
    {
        return $this->settledAt;
    }

    /**
     * Normaliza un texto opcional: recorta espacios y convierte el vacío en null.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
