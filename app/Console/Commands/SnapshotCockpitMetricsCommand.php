<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Cockpit\CockpitPulse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Toma la FOTO HISTÓRICA diaria de los KPIs de la Torre de Control (una fila por métrica y día en
 * `cockpit_metric_snapshots`). El pulso compara el valor de hoy contra el último snapshot anterior para
 * mostrar la variación (▲/▼). Idempotente: re-ejecutar el mismo día ACTUALIZA la fila (no duplica).
 *
 * Lo agenda el scheduler a última hora del día (ver routes/console.php). Degrada con gracia si la tabla
 * aún no existe (entorno sin migrar): avisa y termina 0.
 *
 * Uso: php artisan cockpit:metrics:snapshot
 */
final class SnapshotCockpitMetricsCommand extends Command
{
    protected $signature = 'cockpit:metrics:snapshot {--date= : Fecha ISO (YYYY-MM-DD) de la foto; por defecto hoy}';

    protected $description = 'Guarda la foto diaria de los KPIs de la Torre de Control para calcular deltas.';

    public function handle(CockpitPulse $pulse): int
    {
        $dateOption = $this->option('date');
        $capturedOn = is_string($dateOption) && trim($dateOption) !== '' ? trim($dateOption) : now()->toDateString();

        try {
            $count = 0;
            foreach ($pulse->rawValues() as $key => $value) {
                DB::table('cockpit_metric_snapshots')->updateOrInsert(
                    ['metric_key' => $key, 'captured_on' => $capturedOn],
                    ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
                );
                $count++;
            }

            $this->info("Foto de {$count} KPIs guardada para {$capturedOn}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('No se pudo guardar la foto de KPIs: '.$e->getMessage());

            // Degradación elegante: no revienta el scheduler si la tabla no existe todavía.
            return self::SUCCESS;
        }
    }
}
