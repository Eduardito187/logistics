<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use DateTimeImmutable;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\PromiseId;

/**
 * Puerta de persistencia del agregado DeliveryPromise. El dominio la define; la implementación Eloquent
 * vive en Infrastructure.
 */
interface PromiseRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): PromiseId;

    public function save(DeliveryPromise $promise): void;

    public function findById(PromiseId $id): ?DeliveryPromise;

    /**
     * Ids de las promesas reservadas cuya ventana ya venció al instante dado (reserved_until <= now).
     * Las usa el barrido en lote para expirarlas y liberar su cupo. Lista vacía si no hay ninguna.
     *
     * @return list<PromiseId>
     */
    public function reservedExpiredAt(DateTimeImmutable $now): array;

    /**
     * Promesas que mantienen cupo tomado para una referencia dada (shipment_ref), es decir en estado
     * Reserved o Confirmed. Las usa el puente comercio→promise (ADR 020): confirmar al pagar la orden
     * (las Reserved) y liberar al cancelarla (Reserved y Confirmed). La referencia es un primitivo libre
     * —el id de la orden comercial se guarda ahí—. Lista vacía si no hay ninguna.
     *
     * @return list<PromiseId>
     */
    public function holdingByShipmentRef(string $shipmentRef): array;
}
