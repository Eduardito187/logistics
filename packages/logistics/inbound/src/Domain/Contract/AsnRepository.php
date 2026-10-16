<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Contract;

use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AsnId;

/**
 * Puerto de persistencia del agregado ASN. La implementación vive en Infrastructure.
 */
interface AsnRepository
{
    public function nextIdentity(): AsnId;

    /**
     * Upsert del ASN por id (incluye sus líneas).
     */
    public function save(Asn $asn): void;

    public function findById(AsnId $id): ?Asn;

    /**
     * Lista ASNs filtrando por seller, almacén, estado y/o fecha de cita (cualquiera nullable = sin filtro).
     * La fecha filtra por el día de la ventana de cita.
     *
     * @return list<Asn>
     */
    public function listBy(
        ?string $sellerId = null,
        ?string $warehouseId = null,
        ?string $status = null,
        ?string $appointmentDate = null,
    ): array;

    /**
     * Lista los ASNs agendados con cita entre dos fechas (inclusive), para el calendario de andén.
     *
     * @return list<Asn>
     */
    public function listScheduledBetween(?string $warehouseId, string $fromDate, string $toDate): array;
}
