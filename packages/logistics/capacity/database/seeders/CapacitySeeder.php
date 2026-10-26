<?php

declare(strict_types=1);

namespace Logistics\Capacity\Database\Seeders;

use Illuminate\Container\Container;
use Illuminate\Database\Seeder;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

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

        // Publica las reglas de cupo versionadas (C-CAP-01) si todavía no hay versión: días de servicio,
        // corte, ventanas, horizonte y cupos por unidad, con los nodos reales como scopes. Desde el admin
        // se editan publicando versiones nuevas; desde aquí solo se siembra la base.
        $this->seedRuleset($bus);

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
     * Publica la versión base de las reglas de cupo si la clave aún no tiene ninguna (idempotente).
     */
    private function seedRuleset(CommandBus $bus): void
    {
        $query = Container::getInstance()->make(QueryBus::class);
        $existing = $query->ask(new GetSettingQuery(SettingsCapacityRulesetProvider::SETTING_KEY));
        if (is_array($existing)) {
            return;
        }

        $scopes = array_map(
            static fn (string $nodeId): array => ['node_id' => $nodeId, 'zone_id' => null],
            $this->nodeIds(),
        );

        $bus->dispatch(new SetSettingCommand(
            key: SettingsCapacityRulesetProvider::SETTING_KEY,
            value: [
                'service_days' => [1, 2, 3, 4, 5, 6],
                'order_cutoff' => '12:00',
                'horizon_days' => 14,
                'holidays' => [],
                'windows' => self::SLOTS,
                'windows_by_weekday' => [],
                'totals' => [
                    'deliveries' => 40.0,
                    'weight' => 8000.0,
                    'volume' => 40.0,
                ],
                'totals_by_slot' => [],
                'scopes' => $scopes,
            ],
            effectiveFrom: null,
            createdBy: 'capacity-seeder',
        ));
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
        for ($offset = 0; $offset < 14; $offset++) {
            $dates[] = now()->addDays($offset)->format('Y-m-d');
        }

        return $dates;
    }
}
