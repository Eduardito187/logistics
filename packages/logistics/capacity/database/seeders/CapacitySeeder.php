<?php

declare(strict_types=1);

namespace Logistics\Capacity\Database\Seeders;

use Illuminate\Container\Container;
use Illuminate\Database\Seeder;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Seeder propio del contexto Capacity: define cupos de demostración realistas para los próximos días.
 *
 * No inserta filas directamente: despacha DefineCapacity al bus CQRS, igual que la API o el
 * aprovisionamiento, para que el dominio asigne id (UUID v7) y emita el evento CapacityDefined vía
 * outbox. Es idempotente por clave natural (scope/fecha/franja/unidad): si el cupo ya existe, no lo
 * vuelve a crear, así re-ejecutar el seed no falla por el índice único.
 *
 * Usa nodos reales de Network si ya fueron sembrados; si no hay, cae en un nodo de demostración con
 * UUID fijo para no acoplar el orden de seed entre contextos. El integrador lo invoca desde el
 * DatabaseSeeder raíz; aquí no se toca ese archivo compartido.
 */
final class CapacitySeeder extends Seeder
{
    /** Nodo de respaldo (UUID v7 fijo) cuando Network todavía no tiene datos sembrados. */
    private const FALLBACK_NODE_ID = '019299aa-0000-7000-8000-00000000ca01';

    /** Franjas horarias de demostración por día. */
    private const SLOTS = ['08:00-12:00', '12:00-16:00', '16:00-20:00'];

    public function run(): void
    {
        $bus = Container::getInstance()->make(CommandBus::class);

        foreach ($this->nodeIds() as $nodeId) {
            foreach ($this->dates() as $date) {
                foreach (self::SLOTS as $slot) {
                    $this->define($bus, $nodeId, $date, $slot, 'deliveries', 120.0);
                    $this->define($bus, $nodeId, $date, $slot, 'weight', 8000.0);
                    $this->define($bus, $nodeId, $date, $slot, 'volume', 40.0);
                }
            }
        }
    }

    /**
     * Despacha DefineCapacity si el cupo todavía no existe (idempotencia por clave natural).
     */
    private function define(
        CommandBus $bus,
        string $nodeId,
        string $date,
        string $slot,
        string $unit,
        float $total,
    ): void {
        $exists = CapacityBucketRecord::query()
            ->where('node_id', $nodeId)
            ->whereNull('zone_id')
            ->where('slot_date', $date)
            ->where('time_slot', $slot)
            ->where('unit', $unit)
            ->exists();

        if ($exists) {
            return;
        }

        $bus->dispatch(new DefineCapacityCommand(
            nodeId: $nodeId,
            zoneId: null,
            date: $date,
            timeSlot: $slot,
            unit: $unit,
            total: $total,
        ));
    }

    /**
     * Hasta dos nodos reales de Network; si no hay, el nodo de respaldo.
     *
     * @return list<string>
     */
    private function nodeIds(): array
    {
        $ids = NodeRecord::query()->orderBy('code')->limit(2)->pluck('id')->all();

        /** @var list<string> $ids */
        return $ids === [] ? [self::FALLBACK_NODE_ID] : $ids;
    }

    /**
     * Los próximos tres días (incluido hoy), en formato Y-m-d.
     *
     * @return list<string>
     */
    private function dates(): array
    {
        $dates = [];
        for ($offset = 0; $offset < 3; $offset++) {
            $dates[] = now()->addDays($offset)->format('Y-m-d');
        }

        return $dates;
    }
}
