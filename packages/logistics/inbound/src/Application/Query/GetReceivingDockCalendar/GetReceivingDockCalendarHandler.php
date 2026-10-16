<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\GetReceivingDockCalendar;

use DateTimeImmutable;
use Logistics\Inbound\Application\ReadModel\ReceivingDockDayView;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Arma el calendario de andén de recepción: por cada día del rango, agrega las citas agendadas por franja.
 * Espejo de lectura del calendario de despacho, pero contando ASNs agendados en vez de cupos de despacho.
 *
 * @implements QueryHandler<GetReceivingDockCalendar, list<ReceivingDockDayView>>
 */
final readonly class GetReceivingDockCalendarHandler implements QueryHandler
{
    public function __construct(
        private AsnRepository $asns,
    ) {}

    /**
     * @return list<ReceivingDockDayView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetReceivingDockCalendar);

        $days = max(1, $query->days);
        $from = new DateTimeImmutable($query->fromDate);
        $to = $from->modify('+'.($days - 1).' days');

        $asns = $this->asns->listScheduledBetween(
            $query->warehouseId,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );

        // Índice fecha → (franja → cantidad de citas).
        /** @var array<string, array<string, int>> $byDate */
        $byDate = [];
        foreach ($asns as $asn) {
            $window = $asn->appointmentWindow();
            if ($window === null) {
                continue;
            }
            $byDate[$window->date] ??= [];
            $slot = $window->timeSlot();
            $byDate[$window->date][$slot] = ($byDate[$window->date][$slot] ?? 0) + 1;
        }

        $calendar = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->modify('+'.$i.' days')->format('Y-m-d');
            $slots = [];
            foreach ($byDate[$date] ?? [] as $slot => $count) {
                $slots[] = ['time_slot' => $slot, 'appointments' => $count];
            }
            usort($slots, static fn (array $a, array $b): int => $a['time_slot'] <=> $b['time_slot']);
            $calendar[] = ReceivingDockDayView::of($date, $slots);
        }

        return $calendar;
    }
}
