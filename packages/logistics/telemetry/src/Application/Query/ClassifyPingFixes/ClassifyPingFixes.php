<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ClassifyPingFixes;

use Logistics\Shared\Application\Query\Query;

/**
 * Clasifica las lecturas de un lote ANTES de ingerirlo: cuáles son nuevas y cuáles ya se recibieron (por su
 * FixKey: `fix_id` o `device_id@recorded_at`), para devolver al cliente un resultado por ítem. La ingesta en
 * sí (RecordVehiclePings) vuelve a deduplicar de forma autoritativa; esto es solo el informe.
 *
 * @phpstan-type FixRef array{index: int, recorded_at: string, fix_id?: string|null, device_id?: string|null}
 */
final readonly class ClassifyPingFixes implements Query
{
    /**
     * @param list<FixRef> $fixes
     */
    public function __construct(
        public string $vehicleId,
        public array $fixes,
    ) {}
}
