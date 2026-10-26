<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Throwable;

/**
 * Calcula y persiste la geometría por calles de los planes que quedaron sin ella (armados a mano, por
 * propuesta, reordenados o publicados antes de este arreglo). Idempotente: sin --force solo toca planes
 * con geometría nula. Con --force recalcula todos los no publicados (un plan publicado con geometría no
 * se reescribe: su hoja de ruta está congelada).
 */
final class BackfillPlanGeometryCommand extends Command
{
    /** @var string */
    protected $signature = 'logistics:planning:backfill-geometry
        {--date= : Día de los planes (Y-m-d); por defecto, todos}
        {--force : Recalcular también los planes que ya tienen geometría}';

    /** @var string */
    protected $description = 'Calcula y persiste la geometría de ruta (por calles) de los planes que no la tienen';

    public function handle(PlanGeometryRefresher $refresher): int
    {
        $dateOption = $this->option('date');
        $date = null;
        if (is_string($dateOption) && trim($dateOption) !== '') {
            try {
                $date = PlanDate::fromString(trim($dateOption));
            } catch (Throwable) {
                $this->error("Fecha inválida: {$dateOption} (formato Y-m-d)");

                return self::FAILURE;
            }
        }

        $result = $refresher->backfill($date, (bool) $this->option('force'));

        $this->info(sprintf(
            'Planes revisados: %d · con geometría nueva: %d · sin cambios: %d',
            $result['scanned'],
            $result['updated'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
