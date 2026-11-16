<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\ListBucketsByDate;

use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Service\DispatchSlotAvailability;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los cupos de una fecha como modelos de lectura, EXCLUYENDO las franjas bloqueadas (por el día
 * entero o por la franja, global o del municipio del cupo): el cliente nunca ve un cupo de una franja que
 * dejó de ofrecerse. Es la lectura que alimenta el calendario de despacho de los canales.
 *
 * @implements QueryHandler<ListBucketsByDateQuery, list<CapacityView>>
 */
final readonly class ListBucketsByDateHandler implements QueryHandler
{
    public function __construct(
        private CapacityRepository $buckets,
        private DispatchSlotAvailability $slotAvailability,
    ) {}

    /**
     * @return list<CapacityView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListBucketsByDateQuery);

        $date = SlotDate::fromString($query->date);

        $buckets = $this->buckets->byDate($date);

        // Filtro por municipio en memoria: un día tiene un número acotado de cupos, así no se agrega un
        // método al puerto del repositorio (que obligaría a tocar todos sus implementadores y decoradores).
        if ($query->municipalityId !== null) {
            $buckets = array_values(array_filter(
                $buckets,
                static fn ($bucket): bool => $bucket->scope()->municipalityId === $query->municipalityId,
            ));
        }

        // Excluye las franjas bloqueadas: la disponibilidad se calcula acá, no en el controller. El eje
        // del bloqueo es el municipio del cupo (null = alcance global).
        $buckets = array_values(array_filter(
            $buckets,
            fn ($bucket): bool => !$this->slotAvailability->isBlocked($date, $bucket->slot(), $bucket->scope()->municipalityId),
        ));

        return array_map(CapacityView::fromBucket(...), $buckets);
    }
}
