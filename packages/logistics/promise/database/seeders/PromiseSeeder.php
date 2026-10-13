<?php

declare(strict_types=1);

namespace Logistics\Promise\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Promise\Database\Factories\PromiseFactory;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Seeder propio del contexto Promesa de entrega. Deja datos de demo verosímiles que cubren todo el
 * ciclo de vida de la promesa (cotizada, reservada, confirmada, expirada) repartidos en las zonas de
 * cobertura REALES ya sembradas, para que el admin y las APIs de canales tengan material realista y la
 * relación con la zona no quede huérfana.
 *
 * Lee los ids reales de las tablas upstream (coverage_zones y shipments) siguiendo el mismo patrón que
 * CapacitySeeder: el orden del DatabaseSeeder garantiza que ya están sembradas cuando corre este seeder.
 * Si alguna upstream está vacía, cae en un UUID / referencia generada para no acoplar el orden.
 *
 * No se auto-registra en el DatabaseSeeder raíz (archivo compartido): el integrador lo invoca desde
 * allí con `$this->call(PromiseSeeder::class)`.
 */
final class PromiseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotente: si ya hay promesas de demo sembradas, no se vuelve a crear ninguna (las factories
        // generan promesas por zona y un segundo run las duplicaría). El contexto Promesa es dueño
        // exclusivo de su tabla, así que basta con que tenga filas.
        if (PromiseRecord::query()->exists()) {
            return;
        }

        $zoneIds = $this->zoneIds();
        $shipmentRefs = $this->shipmentRefs();

        foreach ($zoneIds as $zoneId) {
            // Franjas ofrecidas sin reservar aún.
            PromiseFactory::new()->forZone($zoneId)->sequence(...$this->refStates($shipmentRefs))->count(4)->create();

            // Reservas vigentes esperando confirmación.
            PromiseFactory::new()->forZone($zoneId)->reserved()->sequence(...$this->refStates($shipmentRefs))->count(2)->create();

            // Promesas confirmadas en firme.
            PromiseFactory::new()->forZone($zoneId)->confirmed()->sequence(...$this->refStates($shipmentRefs))->count(3)->create();

            // Reservas que vencieron antes de confirmar.
            PromiseFactory::new()->forZone($zoneId)->expired()->sequence(...$this->refStates($shipmentRefs))->count(1)->create();
        }
    }

    /**
     * Zonas de cobertura reales ya sembradas; si no hay, un UUID de respaldo para no romper el seed.
     *
     * @return non-empty-list<string>
     */
    private function zoneIds(): array
    {
        /** @var list<string> $ids */
        $ids = ZoneRecord::query()->orderBy('name')->pluck('id')->all();

        return $ids === [] ? [Uuid::generate()->value] : $ids;
    }

    /**
     * Referencias de envío reales (external_id) para alinear `shipment_ref` con los envíos existentes;
     * si no hay envíos, se deja que la factory genere su referencia por defecto.
     *
     * @return list<string>
     */
    private function shipmentRefs(): array
    {
        /** @var list<string> $refs */
        $refs = ShipmentRecord::query()->orderBy('external_id')->pluck('external_id')->all();

        return $refs;
    }

    /**
     * Estados de secuencia que asignan cíclicamente un `shipment_ref` real a cada promesa creada. Si no
     * hay envíos reales, no se fuerza el valor y la factory usa su referencia de demo por defecto.
     *
     * @param  list<string>                $shipmentRefs
     * @return list<array<string, string>>
     */
    private function refStates(array $shipmentRefs): array
    {
        if ($shipmentRefs === []) {
            return [[]];
        }

        return array_map(
            static fn (string $ref): array => ['shipment_ref' => $ref],
            $shipmentRefs,
        );
    }
}
