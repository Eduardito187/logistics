<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Database\Seeder;
use Logistics\Access\Infrastructure\Seeder\AccessSeeder;
use Logistics\Capacity\Database\Seeders\CapacitySeeder;
use Logistics\Carriers\Infrastructure\Persistence\Seeder\CarriersDemoSeeder;
use Logistics\Coverage\Infrastructure\Seeder\CoverageSeeder;
use Logistics\Fleet\Database\Seeders\FleetSeeder;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Network\Database\Seeders\NetworkSeeder;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Sellers\Database\Seeders\SellersDemoSeeder;
use Logistics\Settings\Database\Seeders\SettingsSeeder;

/**
 * Orquestador de DATOS DE PRUEBA para que el dueño pruebe TODA la app (Flutter) y el panel con TODOS los
 * roles, cubriendo HOY y MAÑANA con etapas distintas:
 *
 *   - HOY   → escenario EN CURSO: ruta del chofer iniciada (paradas entregadas), telemetría en vivo,
 *             recepciones de tienda abiertas y un recojo ya conciliado. Para probar el flujo "a mitad".
 *   - MAÑANA → escenario PLANIFICADO/fresco: envíos recibidos con entrega para mañana, plan + ruta del
 *             chofer SIN iniciar (todas las paradas pendientes, nada entregado), recepciones de tienda
 *             pendientes y cupos activos. Para probar preparar → cargar → escanear → entregar y recibir
 *             desde cero.
 *
 * El chofer, el vehículo y el nodo de tienda son LOS MISMOS ambos días; solo cambian la fecha (ids
 * externos fechados con sufijo Ymd/ymd y plan_date/route_date por día) y la etapa, así re-ejecutar no
 * duplica ni choca. 100% re-ejecutable (todo por firstOrCreate/updateOrCreate/claves de negocio).
 *
 * Uso (dentro del contenedor):
 *   docker compose exec -T app php artisan db:seed --class="Database\Seeders\TestDataSeeder"
 *
 * Solo corre en entornos locales/dev; en cualquier otro no hace nada.
 */
final class TestDataSeeder extends Seeder
{
    private const string TIMEZONE = 'America/La_Paz';

    /** Nodo de referencia del escenario (CD Santa Cruz) para detectar si falta la base. */
    private const string CD_CODE = 'CD-SCZ-01';

    public function run(): void
    {
        if (!app()->environment(['local', 'development', 'dev'])) {
            $this->command?->warn('TestDataSeeder solo corre en entornos locales/dev; no se hizo nada.');

            return;
        }

        // 1) Base fundacional idempotente (nodos / vehículos / zonas / sellers / transportistas). Solo si
        //    aún no está sembrada; todas estas clases son idempotentes y seguras de reejecutar.
        $this->ensureFoundations();

        // 1b) Cupos: SIEMPRE, porque CapacitySeeder siembra una ventana móvil (hoy + 2 días) relativa a la
        //     fecha actual. Reejecutarlo cada día garantiza cupos activos para HOY y MAÑANA (idempotente
        //     por clave natural scope/fecha/franja/unidad).
        $this->call(CapacitySeeder::class);

        // 2) Escenario operativo paramétrico para HOY (en curso) y MAÑANA (planificado), reutilizando el
        //    mismo chofer, vehículo y tienda. Se resuelve una sola instancia del seeder (inyección de
        //    dependencias por contenedor) y se la invoca por fecha + etapa.
        $tz = new DateTimeZone(self::TIMEZONE);
        $today = new DateTimeImmutable('now', $tz);
        $tomorrow = $today->modify('+1 day');

        /** @var TodayScenarioSeeder $scenario */
        $scenario = Container::getInstance()->make(TodayScenarioSeeder::class);
        if ($this->command !== null) {
            $scenario->setCommand($this->command);
        }

        $scenario->buildForDate($today, TodayScenarioSeeder::STAGE_IN_PROGRESS);
        $scenario->buildForDate($tomorrow, TodayScenarioSeeder::STAGE_PLANNED);

        // 3) Variedad adicional de datos (la crea OTRO agente). Si aún no existe, se omite sin fallar.
        if (class_exists(TestDataVarietySeeder::class)) {
            $this->call(TestDataVarietySeeder::class);
        }

        $this->command?->info('TestDataSeeder listo: HOY (en curso) y MAÑANA (planificado) sembrados.');
    }

    /**
     * Siembra la base fundacional solo si falta (nodo CD o vehículos ausentes). Son seeders idempotentes;
     * se corren vía $this->call para que resuelvan sus dependencias por contenedor igual que DatabaseSeeder.
     */
    private function ensureFoundations(): void
    {
        $hasNode = NodeRecord::query()->where('code', self::CD_CODE)->exists();
        $hasVehicles = VehicleModel::query()->exists();
        if ($hasNode && $hasVehicles) {
            return;
        }

        $this->call([
            AccessSeeder::class,
            SettingsSeeder::class,
            NetworkSeeder::class,
            CoverageSeeder::class,
            FleetSeeder::class,
            CarriersDemoSeeder::class,
            SellersDemoSeeder::class,
        ]);
    }
}
