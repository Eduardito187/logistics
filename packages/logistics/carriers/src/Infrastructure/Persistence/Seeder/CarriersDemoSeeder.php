<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Seeder;

use Illuminate\Database\Seeder;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\TariffFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;

/**
 * Seeder propio del contexto Transportistas con datos de demo realistas.
 *
 * Siembra directamente la proyección de lectura (records Eloquent) a través de las fábricas: es la vía
 * estándar de demo y no depende de que el outbox esté presente. El integrador invoca este seeder desde
 * el DatabaseSeeder raíz; también puede correrse con `php artisan db:seed --class=...`.
 *
 * Reproduce los corredores troncales entre departamentos de Bolivia (CD→CD) y un par de corredores
 * CD→tienda, con tarifas por clase de vehículo y liquidaciones en distintos estados del ciclo de vida.
 */
final class CarriersDemoSeeder extends Seeder
{
    /**
     * Corredores troncales de demo: [origen, destino, tipo].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const CORRIDORS = [
        ['cd-santa-cruz', 'cd-la-paz', 'cd_to_cd'],
        ['cd-santa-cruz', 'cd-cochabamba', 'cd_to_cd'],
        ['cd-la-paz', 'cd-cochabamba', 'cd_to_cd'],
        ['cd-santa-cruz', 'store-scz-centro', 'cd_to_store'],
    ];

    public function run(): void
    {
        // Idempotente: si los transportistas de demo ya están sembrados, no se vuelve a insertar
        // (las fábricas usan `tax_id` únicos; un segundo run rompería la restricción de unicidad).
        if (CarrierRecord::query()->whereIn('tax_id', [
            'NIT-1001001', 'NIT-1001002', 'NIT-1001003', 'NIT-1001004', 'NIT-1001005',
        ])->exists()) {
            return;
        }

        // Transportistas activos con reputación variada.
        $activos = [
            CarrierFactory::new()->state(['name' => 'Transportes del Oriente', 'tax_id' => 'NIT-1001001'])->rated(4.6, 18)->createOne(),
            CarrierFactory::new()->state(['name' => 'Andes Cargo', 'tax_id' => 'NIT-1001002'])->rated(4.1, 12)->createOne(),
            CarrierFactory::new()->state(['name' => 'Altiplano Logística', 'tax_id' => 'NIT-1001003'])->rated(3.7, 7)->createOne(),
            CarrierFactory::new()->state(['name' => 'Valle Express', 'tax_id' => 'NIT-1001004'])
                ->withDocuments([['type' => 'insurance_policy', 'reference' => 'POL-2026-3345']])
                ->createOne(),
        ];

        // Transportista suspendido (no debería recibir asignaciones).
        CarrierFactory::new()->state(['name' => 'Chaco Transportes', 'tax_id' => 'NIT-1001005'])->suspended()->create();

        // Tarifas: cada transportista activo cotiza algunos corredores por clase de vehículo.
        foreach ($activos as $index => $carrier) {
            foreach (self::CORRIDORS as $corridorIndex => [$from, $to, $type]) {
                // Variedad de clases y precios para que la consolidación por volumen tenga opciones.
                $class = $type === 'cd_to_store' ? 'truck' : 'trailer';
                $base = 280_000 + ($corridorIndex * 40_000) + ($index * 15_000);

                TariffFactory::new()
                    ->forCarrier((string) $carrier->getKey())
                    ->forCorridor($from, $to, $type)
                    ->forVehicleClass($class)
                    ->priced($base)
                    ->create();
            }
        }

        // Una oferta de tercero (volumen bajo) para el corredor CD→tienda, más barata por clase.
        TariffFactory::new()
            ->forCarrier((string) $activos[1]->getKey())
            ->forCorridor('cd-santa-cruz', 'store-scz-centro', 'cd_to_store')
            ->forVehicleClass('third_party')
            ->priced(120_000)
            ->create();

        // Liquidaciones en distintos estados del ciclo de vida.
        $primero = (string) $activos[0]->getKey();
        $segundo = (string) $activos[1]->getKey();

        SettlementFactory::new()->forCarrier($primero)
            ->forCorridorState('cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', '2026-09', 1_120_000)
            ->paid('PAY-2026-0912')
            ->create();

        SettlementFactory::new()->forCarrier($primero)
            ->forCorridorState('cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', '2026-10', 980_000)
            ->approved()
            ->create();

        SettlementFactory::new()->forCarrier($segundo)
            ->forCorridorState('cd-santa-cruz', 'cd-cochabamba', 'cd_to_cd', '2026-10', 760_000)
            ->create();

        SettlementFactory::new()->forCarrier($segundo)
            ->forCorridorState('cd-la-paz', 'cd-cochabamba', 'cd_to_cd', '2026-10', 540_000)
            ->disputed('Weight on the invoice does not match the manifest.')
            ->create();
    }
}
