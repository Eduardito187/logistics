<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain;

use Logistics\Capacity\Domain\Event\CapacityConsumed;
use Logistics\Capacity\Domain\Event\CapacityDefined;
use Logistics\Capacity\Domain\Event\CapacityReleased;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Cupo de capacidad: cuánta capacidad (en una unidad) hay para un nodo/zona, una fecha y una franja,
 * y cuánta se lleva consumida. Es la raíz de agregado del contexto capacity.
 *
 * La única regla dura que aplica el dominio es no consumir más de lo disponible. A qué bucket imputar
 * cada envío lo decide planning; acá solo se lleva la cuenta.
 */
final class CapacityBucket extends AggregateRoot
{
    private function __construct(
        private readonly CapacityBucketId $id,
        private readonly BucketScope $scope,
        private readonly SlotDate $date,
        private readonly TimeSlot $slot,
        private readonly CapacityUnit $unit,
        private readonly Quantity $total,
        private Quantity $used,
    ) {}

    /**
     * Define un cupo nuevo: nace con consumo cero y graba el evento CapacityDefined.
     */
    public static function define(
        CapacityBucketId $id,
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
        Quantity $total,
    ): self {
        $bucket = new self($id, $scope, $date, $slot, $unit, $total, Quantity::zero());
        $bucket->recordEvent(new CapacityDefined(
            $id->toString(),
            $scope->nodeId,
            $scope->zoneId,
            $date->toString(),
            $slot->toString(),
            $unit->value,
            $total->value(),
            $scope->municipalityId,
            $scope->kind->value,
            $scope->warehouseId,
            $scope->dockId,
        ));

        return $bucket;
    }

    /**
     * Rehidrata un bucket desde su estado persistido. No graba eventos: reconstruye, no crea.
     */
    public static function fromState(
        CapacityBucketId $id,
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
        Quantity $total,
        Quantity $used,
    ): self {
        return new self($id, $scope, $date, $slot, $unit, $total, $used);
    }

    /**
     * Consume capacidad. Lanza si la cantidad excede lo disponible (nunca se sobrevende).
     */
    public function consume(Quantity $amount): void
    {
        if ($amount->isGreaterThan($this->available())) {
            throw CapacityException::because(sprintf(
                'consumo %.3f excede la capacidad disponible %.3f del bucket %s',
                $amount->value(),
                $this->available()->value(),
                $this->id->toString(),
            ));
        }

        $this->used = $this->used->add($amount);
        $this->recordEvent(new CapacityConsumed(
            $this->id->toString(),
            $this->unit->value,
            $amount->value(),
            $this->used->value(),
            $this->available()->value(),
        ));
    }

    /**
     * Libera capacidad previamente consumida. El consumo nunca baja de 0 (subtract está acotado).
     */
    public function release(Quantity $amount): void
    {
        $this->used = $this->used->subtract($amount);
        $this->recordEvent(new CapacityReleased(
            $this->id->toString(),
            $this->unit->value,
            $amount->value(),
            $this->used->value(),
            $this->available()->value(),
        ));
    }

    public function available(): Quantity
    {
        return $this->total->subtract($this->used);
    }

    public function isFull(): bool
    {
        return $this->available()->isZero();
    }

    public function id(): CapacityBucketId
    {
        return $this->id;
    }

    public function scope(): BucketScope
    {
        return $this->scope;
    }

    public function date(): SlotDate
    {
        return $this->date;
    }

    public function slot(): TimeSlot
    {
        return $this->slot;
    }

    public function unit(): CapacityUnit
    {
        return $this->unit;
    }

    public function total(): Quantity
    {
        return $this->total;
    }

    public function used(): Quantity
    {
        return $this->used;
    }
}
