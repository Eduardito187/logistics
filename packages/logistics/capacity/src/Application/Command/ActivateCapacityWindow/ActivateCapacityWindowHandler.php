<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ActivateCapacityWindow;

use Logistics\Capacity\Domain\ActiveHorizon;
use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Capacity\Domain\Contract\CapacityActivationDispatcher;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Activa cupos por rango de fechas y por municipio. Valida el rango, los días de la semana y las
 * ventanas; por cada municipio AVANZA el horizonte activo hasta `toDate` (crea el registro si no
 * existía) y publica el hecho al outbox; y encola el trabajo asíncrono que materializa los buckets día a
 * día, troceado por lotes (los días bloqueados e inactivos se saltan en el worker).
 *
 * El save del horizonte y el append al outbox corren en la misma transacción (decorador transaccional
 * del CommandBus); el job se encola DESPUÉS del commit (afterCommit) desde el dispatcher de la cola.
 *
 * @implements CommandHandler<ActivateCapacityWindowCommand>
 */
final readonly class ActivateCapacityWindowHandler implements CommandHandler
{
    /** Tope de días de rango para evitar activaciones absurdas (dos años). */
    private const int MAX_RANGE_DAYS = 732;

    public function __construct(
        private ActiveHorizonRepository $horizons,
        private RecordDomainEvents $recordEvents,
        private CapacityActivationDispatcher $dispatcher,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ActivateCapacityWindowCommand);

        $from = SlotDate::fromString($command->fromDate);
        $to = SlotDate::fromString($command->toDate);
        if ($from->isAfter($to)) {
            throw CapacityException::because('el rango de activación es inválido: la fecha inicial es posterior a la final');
        }
        $this->guardRangeLength($from, $to);

        $weekdays = $this->parseWeekdays($command->weekdays);
        $windows = $this->parseWindows($command->windows);
        $municipalityIds = $this->parseMunicipalities($command->municipalityIds);

        foreach ($municipalityIds as $municipalityId) {
            $this->advanceHorizon($municipalityId, $to);
            $this->dispatcher->dispatch($municipalityId, $from, $to, $weekdays, $windows);
        }
    }

    /**
     * Crea o avanza el horizonte activo del municipio hasta `to` y publica el hecho si cambió.
     */
    private function advanceHorizon(string $municipalityId, SlotDate $to): void
    {
        $horizon = $this->horizons->findFor($municipalityId);

        if ($horizon === null) {
            $horizon = ActiveHorizon::start($this->horizons->nextIdentity(), $municipalityId, $to);
            $this->horizons->save($horizon);
            $this->recordEvents->record($horizon->releaseEvents(), 'capacity.active_horizon');

            return;
        }

        if ($horizon->extendTo($to)) {
            $this->horizons->save($horizon);
            $this->recordEvents->record($horizon->releaseEvents(), 'capacity.active_horizon');
        }
    }

    private function guardRangeLength(SlotDate $from, SlotDate $to): void
    {
        $days = (int) $from->toDateTime()->diff($to->toDateTime())->days;
        if ($days > self::MAX_RANGE_DAYS) {
            throw CapacityException::because('el rango de activación supera el máximo de '.self::MAX_RANGE_DAYS.' días');
        }
    }

    /**
     * @param  list<int> $raw
     * @return list<int>
     */
    private function parseWeekdays(array $raw): array
    {
        $days = [];
        foreach ($raw as $value) {
            $day = (int) $value;
            if ($day < 1 || $day > 7) {
                throw CapacityException::because("día de la semana fuera de rango (1..7): {$day}");
            }
            $days[$day] = $day;
        }
        if ($days === []) {
            throw CapacityException::because('la activación necesita al menos un día de la semana activo');
        }

        $days = array_values($days);
        sort($days);

        return $days;
    }

    /**
     * @param  list<array{time_slot: string, unit: string, quantity: float|int}> $raw
     * @return list<ActivationWindow>
     */
    private function parseWindows(array $raw): array
    {
        if ($raw === []) {
            throw CapacityException::because('la activación necesita al menos una ventana (franja + unidad + cantidad)');
        }

        $windows = [];
        foreach ($raw as $entry) {
            $windows[] = ActivationWindow::fromArray($entry);
        }

        return $windows;
    }

    /**
     * @param  list<string> $raw
     * @return list<string>
     */
    private function parseMunicipalities(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            $value = trim($value);
            if ($value !== '') {
                $ids[$value] = $value;
            }
        }
        if ($ids === []) {
            throw CapacityException::because('la activación necesita al menos un municipio');
        }

        return array_values($ids);
    }
}
