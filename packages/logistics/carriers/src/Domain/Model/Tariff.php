<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Model;

use DateTimeImmutable;
use Logistics\Carriers\Domain\Event\TariffSet;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\TariffId;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Tarifa de un transportista para un corredor y una clase de vehículo, vigente desde una fecha.
 *
 * Referencia al transportista por su id como texto (`carrierId`), a propósito, para que el precio de un
 * corredor pueda consultarse sin cargar el agregado Carrier. El dominio no decide qué tarifa se aplica a
 * un envío (eso es planning); solo guarda el precio y su vigencia.
 */
final class Tariff extends AggregateRoot
{
    private function __construct(
        private readonly TariffId $id,
        private readonly string $carrierId,
        private readonly CorridorRef $corridor,
        private readonly VehicleClass $vehicleClass,
        private readonly Money $price,
        private readonly DateTimeImmutable $effectiveFrom,
    ) {}

    /**
     * Define una tarifa nueva y graba el evento correspondiente.
     */
    public static function define(
        TariffId $id,
        string $carrierId,
        CorridorRef $corridor,
        VehicleClass $vehicleClass,
        Money $price,
        DateTimeImmutable $effectiveFrom,
    ): self {
        $tariff = new self($id, $carrierId, $corridor, $vehicleClass, $price, $effectiveFrom);
        $tariff->recordEvent(new TariffSet(
            $id->toString(),
            $carrierId,
            $corridor->from,
            $corridor->to,
            $corridor->type,
            $vehicleClass->value,
            $price->cents,
            $price->currency,
            $effectiveFrom->format('Y-m-d'),
        ));

        return $tariff;
    }

    /**
     * Reconstituye una tarifa desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        TariffId $id,
        string $carrierId,
        CorridorRef $corridor,
        VehicleClass $vehicleClass,
        Money $price,
        DateTimeImmutable $effectiveFrom,
    ): self {
        return new self($id, $carrierId, $corridor, $vehicleClass, $price, $effectiveFrom);
    }

    /**
     * ¿Está vigente en la fecha dada? Vigente desde `effectiveFrom` inclusive.
     */
    public function isEffectiveOn(DateTimeImmutable $ref): bool
    {
        return $this->effectiveFrom->setTime(0, 0, 0) <= $ref->setTime(0, 0, 0);
    }

    public function id(): TariffId
    {
        return $this->id;
    }

    public function carrierId(): string
    {
        return $this->carrierId;
    }

    public function corridor(): CorridorRef
    {
        return $this->corridor;
    }

    public function vehicleClass(): VehicleClass
    {
        return $this->vehicleClass;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function effectiveFrom(): DateTimeImmutable
    {
        return $this->effectiveFrom;
    }
}
