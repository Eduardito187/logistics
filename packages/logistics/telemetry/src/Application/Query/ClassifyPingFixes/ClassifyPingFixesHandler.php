<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ClassifyPingFixes;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\ValueObject\FixKey;

/**
 * Marca cada lectura como `accepted` (nueva) o `duplicate` (repetida dentro del lote o ya persistida). Una sola
 * query acotada por vehículo + rango de `recorded_at` del lote.
 *
 * @implements QueryHandler<ClassifyPingFixes, array<int, string>>
 */
final readonly class ClassifyPingFixesHandler implements QueryHandler
{
    public function __construct(
        private PingRepository $pings,
    ) {}

    /**
     * @return array<int, string> índice del ítem → 'accepted' | 'duplicate'
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ClassifyPingFixes);

        $keys = [];
        $from = null;
        $to = null;
        foreach ($query->fixes as $fix) {
            $recordedAt = new DateTimeImmutable($fix['recorded_at']);
            $keys[$fix['index']] = FixKey::of($fix['fix_id'] ?? null, $fix['device_id'] ?? null, $recordedAt);
            $from = ($from === null || $recordedAt < $from) ? $recordedAt : $from;
            $to = ($to === null || $recordedAt > $to) ? $recordedAt : $to;
        }

        if ($keys === [] || $from === null || $to === null) {
            return [];
        }

        $known = array_flip($this->pings->existingFixKeys(
            $query->vehicleId,
            array_values(array_unique($keys)),
            $from,
            $to,
        ));

        $seen = [];
        $result = [];
        foreach ($keys as $index => $key) {
            $result[$index] = (isset($known[$key]) || isset($seen[$key])) ? 'duplicate' : 'accepted';
            $seen[$key] = true;
        }

        return $result;
    }
}
