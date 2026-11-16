<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Throwable;

/**
 * Calcula y persiste la geometría por calles de los planes que quedaron sin ella (armados a mano, por
 * propuesta, reordenados o publicados antes de este arreglo). Idempotente: SIN --force solo escanea planes con
 * geometría NULA. CON --force escanea TODOS y recalcula los que la necesitan: los no publicados con geometría
 * ya válida y los que tienen geometría DEGENERADA (colapsada a un punto, basura) aunque estén publicados; un
 * plan publicado con geometría VÁLIDA (dibujable) nunca se reescribe (su hoja de ruta está congelada). Una
 * geometría degenerada además se AUTO-SANA al leer la ruta (el mapa la recalcula al abrirla), sin este comando.
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
