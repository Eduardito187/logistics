<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Queue;

use App\Observability\Span;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Logistics\Planning\Application\Service\DailyPlanRunner;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Throwable;

/**
 * Trabajo encolado que ejecuta el cálculo PESADO del auto-planificador FUERA del request del usuario
 * (principio 4 de la arquitectura): empaque, ruteo por cercanía y persistencia de los planes del día.
 *
 * - Asíncrono: corre en el worker (cola Valkey), nunca en el request que lo disparó.
 * - Idempotente: ShouldBeUnique por corrida (uniqueId = runId) evita que dos copias se procesen a la
 *   vez; y el runner, además, no re-procesa una corrida ya completada ni duplica planes.
 * - Atómico: el trabajo se envuelve en una transacción, de modo que los planes y los mensajes del
 *   outbox (planning.plan_generated, planning.plan_proposed) se confirman juntos o no se confirman.
 *
 * Lleva solo primitivos y el value object de parámetros (serializable): nada de modelos Eloquent.
 */
final class GenerateDailyPlanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Reintentos ante fallos transitorios (p. ej. corte de red con el ruteador). */
    public int $tries = 3;

    /** Ventana de unicidad (segundos): protege de disparos duplicados del mismo día+modo. */
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly string $runId,
        private readonly string $planDate,
        private readonly AutoPlanParameters $params,
    ) {
        // Cola dedicada para separar la planificación masiva del resto del trabajo del worker.
        $this->onQueue('planning');
    }

    public function handle(DailyPlanRunner $runner, ConnectionInterface $connection): void
    {
        // Ola 5: se cronometra la generación completa (histograma + log con request_id) sin tocar su lógica.
        Span::measure('planning.daily_plan', function () use ($runner, $connection): void {
            // El paso a PROCESSING se confirma antes de la transacción del cálculo: así la app lo ve (y
            // recibe su aviso en tiempo real) mientras la corrida trabaja, no recién al terminar.
            $runner->start(AutoPlanRunId::fromString($this->runId));

            $connection->transaction(function () use ($runner): void {
                $runner->run(
                    AutoPlanRunId::fromString($this->runId),
                    PlanDate::fromString($this->planDate),
                    $this->params,
                );
            });
        });
    }

    /**
     * Agotados los reintentos, la corrida queda FALLIDA con su motivo. Hace falta acá porque lo que el
     * runner guarda al fallar vive dentro de la transacción del intento y se revierte con ella; sin esto
     * la corrida quedaría «en proceso» para siempre y bloquearía volver a dispararla.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            app(DailyPlanRunner::class)->markFailed(
                AutoPlanRunId::fromString($this->runId),
                $exception?->getMessage() ?? 'unknown error',
            );
        } catch (Throwable) {
            // Sin base disponible no hay nada más que hacer: el trabajo ya quedó como fallido en la cola.
        }
    }

    /**
     * Clave de unicidad del trabajo: una corrida se procesa una sola vez a la vez.
     */
    public function uniqueId(): string
    {
        return $this->runId;
    }
}
