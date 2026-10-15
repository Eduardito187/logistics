<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Logistics\Capacity\Application\Service\CapacityWindowActivator;
use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Trabajo encolado que materializa los cupos de una activación por rango para UN municipio, troceado por
 * LOTES de días (principio 4: el trabajo pesado nunca corre en el request del usuario). Procesa un lote
 * con CapacityWindowActivator y, si el rango no terminó, se re-encola a sí mismo desde la fecha de
 * continuación. Así un rango grande (p. ej. dos meses × varias ventanas) se procesa en pasos acotados.
 *
 * - Asíncrono: corre en el worker (cola 'planning'), nunca en el request que lo disparó.
 * - Idempotente: DefineCapacity se salta los cupos ya existentes por su clave natural (municipio
 *   incluido), así que reintentos o solapes no duplican cupos; ShouldBeUnique evita dos copias del mismo
 *   lote a la vez.
 *
 * Lleva solo primitivos (fechas, días de la semana, ventanas como arrays): nada de objetos de dominio
 * ni modelos Eloquent cruza la cola.
 */
final class ActivateCapacityWindowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Días de calendario que procesa cada lote antes de re-encolarse (acota la duración del job). */
    public const int CHUNK_DAYS = 14;

    /** Reintentos ante fallos transitorios. */
    public int $tries = 3;

    /** Ventana de unicidad (segundos) del lote. */
    public int $uniqueFor = 3600;

    /**
     * @param list<int>                                                         $weekdays
     * @param list<array{time_slot: string, unit: string, quantity: float|int}> $windows
     */
    public function __construct(
        private readonly string $municipalityId,
        private readonly string $fromDate,
        private readonly string $toDate,
        private readonly array $weekdays,
        private readonly array $windows,
    ) {
        // Cola dedicada para separar la activación masiva del resto del trabajo del worker.
        $this->onQueue('planning');
    }

    public function handle(CapacityWindowActivator $activator): void
    {
        $result = $activator->activateChunk(
            $this->municipalityId,
            SlotDate::fromString($this->fromDate),
            SlotDate::fromString($this->toDate),
            $this->weekdays,
            array_map(ActivationWindow::fromArray(...), $this->windows),
            self::CHUNK_DAYS,
        );

        // Si el rango no terminó en este lote, continúa desde la fecha devuelta en un job nuevo.
        if ($result->nextFrom !== null) {
            self::dispatch(
                $this->municipalityId,
                $result->nextFrom->toString(),
                $this->toDate,
                $this->weekdays,
                $this->windows,
            );
        }
    }

    /**
     * Clave de unicidad: un lote por municipio + fecha de inicio + fecha fin. La continuación cambia la
     * fecha de inicio, así que cada lote tiene su propia clave y no se bloquea con el siguiente.
     */
    public function uniqueId(): string
    {
        return "{$this->municipalityId}|{$this->fromDate}|{$this->toDate}";
    }
}
