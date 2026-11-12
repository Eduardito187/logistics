<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use DateTimeImmutable;
use Logistics\Fleet\Domain\ValueObject\VehicleFitnessLogEntry;

/**
 * Bitácora append-only (auditoría) de la aptitud de los vehículos: cada vez que uno queda NO APTO por un
 * checklist y cada vez que un supervisor lo habilita. Se escribe en la misma transacción que el cambio
 * del vehículo (la abre el decorador transaccional del CommandBus).
 */
interface VehicleFitnessLog
{
    public const string ACTION_MARKED_UNFIT = 'marked_unfit';

    public const string ACTION_CLEARED = 'cleared';

    /**
     * Agrega un asiento a la bitácora con el instante en que ocurrió.
     */
    public function append(VehicleFitnessLogEntry $entry, DateTimeImmutable $occurredAt): void;
}
