<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use DateTimeImmutable;

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
     * @param list<string> $failedItems
     */
    public function append(
        string $vehicleId,
        string $action,
        DateTimeImmutable $occurredAt,
        ?string $reason,
        array $failedItems,
        ?string $inspectionId,
        ?string $actorId,
        ?string $actorLabel,
    ): void;
}
