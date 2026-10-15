<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendar;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendarHandler;
use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Genera el calendario de cupos del horizonte a partir de las reglas vigentes: materializa un
 * CapacityBucket por cada fecha/franja/unidad/scope que las reglas prescriben y que aún no existe. Lo
 * corre el scheduler (p. ej. a diario para empujar el horizonte) o se invoca a mano. Delega en el
 * handler, que despacha un DefineCapacity por cupo vía el CommandBus (idempotente). Imprime el resumen.
 */
final class GenerateDispatchCalendarCommand extends Command
{
    protected $signature = 'logistics:capacity:generate-calendar
        {--from= : Start date (Y-m-d). Defaults to today.}
        {--days= : Service-day horizon override. Defaults to the ruleset horizon.}
        {--at= : Ruleset version date (ISO 8601). Defaults to now.}';

    protected $description = 'Generate the dispatch capacity calendar from the configured capacity rules';

    public function handle(GenerateDispatchCalendarHandler $handler): int
    {
        $from = $this->stringOption('from');
        $at = $this->stringOption('at');
        $daysRaw = $this->stringOption('days');
        $days = $daysRaw !== null && is_numeric($daysRaw) ? (int) $daysRaw : null;

        try {
            $handler->handle(new GenerateDispatchCalendar($from, $days, $at));
        } catch (CapacityException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Dispatch calendar generated: %d buckets created, %d already present.',
            $handler->lastCreated(),
            $handler->lastSkipped(),
        ));

        return self::SUCCESS;
    }

    /**
     * Lee una opción como string no vacío, o null si no vino o vino vacía.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
