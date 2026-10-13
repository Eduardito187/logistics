<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListAvailableSlots;

use DateTimeImmutable;
use Logistics\Promise\Application\ReadModel\AvailableSlotView;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<ListAvailableSlots, list<AvailableSlotView>>
 */
final class ListAvailableSlotsHandler implements QueryHandler
{
    public function __construct(
        private readonly AvailabilityProvider $availability,
    ) {}

    /**
     * @return list<AvailableSlotView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListAvailableSlots) {
            throw PromiseException::because('ListAvailableSlotsHandler solo maneja ListAvailableSlots');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $query->date);
        if ($date === false) {
            throw PromiseException::because("fecha inválida (se esperaba Y-m-d): {$query->date}");
        }

        return array_map(
            static fn (DeliverySlot $slot): AvailableSlotView => AvailableSlotView::fromSlot($slot),
            $this->availability->availableSlots($query->zoneId, $date),
        );
    }
}
