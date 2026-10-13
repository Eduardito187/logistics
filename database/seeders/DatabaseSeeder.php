<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Logistics\Access\Infrastructure\Seeder\AccessSeeder;
use Logistics\Capacity\Database\Seeders\CapacitySeeder;
use Logistics\Carriers\Infrastructure\Persistence\Seeder\CarriersDemoSeeder;
use Logistics\Coverage\Infrastructure\Seeder\CoverageSeeder;
use Logistics\Execution\Database\Seeders\ExecutionDemoSeeder;
use Logistics\Fleet\Database\Seeders\FleetSeeder;
use Logistics\Labels\Database\Seeders\LabelsSeeder;
use Logistics\Network\Database\Seeders\NetworkSeeder;
use Logistics\Notifications\Database\Seeders\NotificationsSeeder;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\Seeders\OutboxSeeder;
use Logistics\Planning\Database\Seeders\PlanningDemoSeeder;
use Logistics\Promise\Database\Seeders\PromiseSeeder;
use Logistics\SapConnector\Infrastructure\Persistence\Seeders\SapConnectorSeeder;
use Logistics\Settings\Database\Seeders\SettingsSeeder;
use Logistics\Shipments\Infrastructure\Persistence\Seeders\ShipmentsDemoSeeder;
use Logistics\Telemetry\Infrastructure\Seeder\TelemetryDemoSeeder;
use Logistics\Tracking\Database\Seeders\TrackingSeeder;

/**
 * Orquestador raíz del seeding. Encadena el seeder propio de cada bounded context en orden de
 * dependencia: primero los datos maestros (settings, red, cobertura, flota, transportistas, cupos),
 * luego los contextos operativos que los referencian (promesa, envíos, planificación, ejecución,
 * tracking, etiquetas) y por último lo transversal (notificaciones, conector SAP, outbox).
 *
 * Cada package expone su propio Seeder; este archivo solo decide el orden. Reintegrar un package
 * nuevo = agregar aquí su Seeder en la posición que le corresponda por dependencia.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Siembra la base de la plataforma.
     */
    public function run(): void
    {
        // Usuario de desarrollo para entrar al panel Filament y a la API en entornos locales.
        // Idempotente: el seeder puede correrse varias veces sin violar la unicidad del email.
        $admin = User::firstOrCreate(
            ['email' => 'admin@dismac.test'],
            ['name' => 'Admin', 'password' => bcrypt('password123')],
        );
        // El administrador del backoffice lleva el rol Admin (acceso total). Idempotente: assignRole
        // hace firstOrCreate sobre `user_roles`. Sin este rol, los widgets validados por rol (p. ej.
        // PlatformStatsWidget) se ocultarían y el tablero quedaría vacío.
        $admin->assignRole(Role::Admin);

        // Otras cuentas de backoffice ya existentes también reciben Admin si están presentes.
        User::query()->where('email', 'ehhuallata@dismac.com.bo')->first()?->assignRole(Role::Admin);

        $this->call([
            // 0) Acceso: catálogo de permisos, roles de sistema y usuario admin de demo. Fundacional e
            // independiente; va primero para que exista el RBAC antes que cualquier dato operativo.
            AccessSeeder::class,

            // 1) Datos maestros / configuración (no dependen de nadie).
            SettingsSeeder::class,

            // 2) Topología física y geográfica: nodos y zonas de cobertura.
            NetworkSeeder::class,
            CoverageSeeder::class,

            // 3) Recursos de transporte: flota propia y transportistas externos.
            FleetSeeder::class,
            CarriersDemoSeeder::class,

            // 4) Capacidad: buckets por nodo/zona (dependen de red y cobertura).
            CapacitySeeder::class,

            // 5) Promesa de entrega: cotiza/reserva cupos sobre cobertura y capacidad.
            PromiseSeeder::class,

            // 6) Envíos: pedidos recibidos que referencian red y cobertura.
            ShipmentsDemoSeeder::class,

            // 7) Planificación: arma rutas/planes sobre envíos, flota y capacidad.
            PlanningDemoSeeder::class,

            // 8) Ejecución: lo que ocurre en ruta sobre los planes.
            ExecutionDemoSeeder::class,

            // 9) Tracking: estados visibles derivados de ejecución/envíos.
            TrackingSeeder::class,

            // 9b) Telemetría: geocercas de demo + recorrido GPS (pings/trip) de vehículos existentes.
            TelemetryDemoSeeder::class,

            // 10) Etiquetas QR de bultos ya conocidos.
            LabelsSeeder::class,

            // 11) Transversales: notificaciones, conector SAP y outbox.
            NotificationsSeeder::class,
            SapConnectorSeeder::class,
            OutboxSeeder::class,
        ]);
    }
}
