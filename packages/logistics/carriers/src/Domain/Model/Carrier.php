<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Model;

use Logistics\Carriers\Domain\Event\CarrierRated;
use Logistics\Carriers\Domain\Event\CarrierReactivated;
use Logistics\Carriers\Domain\Event\CarrierRegistered;
use Logistics\Carriers\Domain\Event\CarrierSuspended;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CarrierStatus;
use Logistics\Carriers\Domain\ValueObject\Rating;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Transportista tercero: identidad, nombre, NIT, estado, promedio de calificación y documentos.
 *
 * El promedio de calificación se mantiene incremental: se guarda el promedio y la cantidad de
 * calificaciones acumuladas, de modo que `addRating` recalcula el promedio sin recorrer el histórico.
 * El agregado no conoce sus tarifas (son otro agregado, Tariff); solo su aptitud y su desempeño.
 */
final class Carrier extends AggregateRoot
{
    /**
     * @param list<array{type: string, reference: string}> $documents
     */
    private function __construct(
        private readonly CarrierId $id,
        private string $name,
        private readonly string $taxId,
        private CarrierStatus $status,
        private ?float $ratingAverage,
        private int $ratingCount,
        private array $documents,
    ) {}

    /**
     * Registra un transportista nuevo. Arranca activo y sin calificaciones.
     *
     * @param list<array{type: string, reference: string}> $documents
     */
    public static function register(
        CarrierId $id,
        string $name,
        string $taxId,
        array $documents = [],
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw CarriersException::because('El nombre del transportista no puede estar vacío.');
        }
        $taxId = trim($taxId);
        if ($taxId === '') {
            throw CarriersException::because('El NIT del transportista no puede estar vacío.');
        }

        $carrier = new self($id, $name, $taxId, CarrierStatus::Active, null, 0, array_values($documents));
        $carrier->recordEvent(new CarrierRegistered(
            $id->toString(),
            $name,
            $taxId,
            CarrierStatus::Active->value,
        ));

        return $carrier;
    }

    /**
     * Reconstituye un transportista desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<array{type: string, reference: string}> $documents
     */
    public static function reconstitute(
        CarrierId $id,
        string $name,
        string $taxId,
        CarrierStatus $status,
        ?float $ratingAverage,
        int $ratingCount,
        array $documents,
    ): self {
        return new self($id, $name, $taxId, $status, $ratingAverage, $ratingCount, array_values($documents));
    }

    /**
     * Suspende al transportista. Idempotente: si ya está suspendido, no hace nada ni graba evento.
     */
    public function suspend(): void
    {
        if ($this->status === CarrierStatus::Suspended) {
            return;
        }
        $this->status = CarrierStatus::Suspended;
        $this->recordEvent(new CarrierSuspended($this->id->toString()));
    }

    /**
     * Reactiva al transportista. Idempotente: si ya está activo, no hace nada ni graba evento.
     */
    public function reactivate(): void
    {
        if ($this->status === CarrierStatus::Active) {
            return;
        }
        $this->status = CarrierStatus::Active;
        $this->recordEvent(new CarrierReactivated($this->id->toString()));
    }

    /**
     * Agrega una calificación y recalcula el promedio de forma incremental.
     */
    public function addRating(Rating $rating): void
    {
        $previousAverage = $this->ratingAverage ?? 0.0;
        $this->ratingAverage = round(
            (($previousAverage * $this->ratingCount) + $rating->value) / ($this->ratingCount + 1),
            2,
        );
        $this->ratingCount++;

        $this->recordEvent(new CarrierRated(
            $this->id->toString(),
            $rating->value,
            $this->ratingAverage,
            $this->ratingCount,
        ));
    }

    /**
     * @param array{type: string, reference: string} $document
     */
    public function addDocument(array $document): void
    {
        $this->documents[] = $document;
    }

    public function id(): CarrierId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function taxId(): string
    {
        return $this->taxId;
    }

    public function status(): CarrierStatus
    {
        return $this->status;
    }

    public function averageRating(): ?float
    {
        return $this->ratingAverage;
    }

    public function ratingCount(): int
    {
        return $this->ratingCount;
    }

    /**
     * @return list<array{type: string, reference: string}>
     */
    public function documents(): array
    {
        return $this->documents;
    }
}
