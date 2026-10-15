<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Seeders;

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Logistics\Fleet\Database\Factories\CrewFactory;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\FleetDocumentFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Database\Factories\PreTripInspectionFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Seeder propio del contexto Fleet: carga una flota de demo coherente para el admin y los tableros.
 *
 * Lo invoca el integrador desde el DatabaseSeeder raíz (regla: no tocar el seeder compartido aquí).
 * Mantiene la coherencia entre entidades: las tripulaciones referencian choferes y vehículos reales,
 * las inspecciones pre-salida referencian vehículo + chofer reales, y los documentos referencian a sus
 * dueños reales. Incluye a propósito algún documento y alguna licencia vencidos para poblar las alertas.
 */
final class FleetSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotente: si la flota de demo ya está sembrada, no se vuelve a crear (las factories generan
        // placas/documentos y un segundo run duplicaría la flota). El contexto Fleet es dueño exclusivo
        // de fleet_vehicles, así que basta con que la tabla tenga filas.
        if (VehicleModel::query()->exists()) {
            return;
        }

        // Nodos base reales de Network (ya sembrados por el orden del DatabaseSeeder): los vehículos
        // cuelgan de un CD real para que la relación `homeBaseNode` no quede huérfana en el admin. Si
        // Network todavía no tiene datos, se cae en un UUID generado para no acoplar el seeder al orden.
        $homeBaseNodeIds = $this->homeBaseNodeIds();
        $primaryNodeId = $homeBaseNodeIds[0];

        // ── Vehículos: disponibles (repartidos por nodo real), uno en mantenimiento y uno con documento vencido. ──
        $vehicles = VehicleFactory::new()
            ->count(6)
            ->sequence(fn (Sequence $sequence): array => [
                'home_base_node_id' => $homeBaseNodeIds[$sequence->index % count($homeBaseNodeIds)],
            ])
            ->create();
        VehicleFactory::new()->homeBase($primaryNodeId)->maintenance()->create();
        VehicleFactory::new()->homeBase($primaryNodeId)->withExpiredDocument()->create();

        $vehicleIds = array_values(array_map('strval', $vehicles->pluck('id')->all()));

        // ── Choferes: activos habilitados para los vehículos, uno con licencia vencida. ──
        $drivers = DriverFactory::new()
            ->count(6)
            ->enabledFor($vehicleIds)
            ->create();
        DriverFactory::new()->withExpiredLicense()->create();

        // ── Ayudantes: activos y uno dado de baja. ──
        $helpers = HelperFactory::new()->count(4)->create();
        HelperFactory::new()->inactive()->create();

        $driverIds = $drivers->pluck('id')->all();
        $helperIds = $helpers->pluck('id')->all();

        // ── Tripulaciones del día: chofer + vehículo + un par de ayudantes. ──
        $today = date('Y-m-d');
        $crewCount = min(count($driverIds), count($vehicleIds), 3);
        for ($i = 0; $i < $crewCount; $i++) {
            CrewFactory::new()
                ->forDriver((string) $driverIds[$i])
                ->forVehicle((string) $vehicleIds[$i])
                ->withHelpers(array_values(array_slice(array_map('strval', $helperIds), 0, 2)))
                ->assembledFor($today)
                ->create();
        }

        // ── Inspecciones pre-salida: aprobadas y una no apta. ──
        $inspectionCount = min(count($driverIds), count($vehicleIds), 3);
        for ($i = 0; $i < $inspectionCount; $i++) {
            PreTripInspectionFactory::new()
                ->forVehicle((string) $vehicleIds[$i])
                ->forDriver((string) $driverIds[$i])
                ->create();
        }
        if ($vehicleIds !== [] && $driverIds !== []) {
            PreTripInspectionFactory::new()
                ->forVehicle((string) $vehicleIds[0])
                ->forDriver((string) $driverIds[0])
                ->failed()
                ->create();
        }

        // ── Documentos de flota con archivo: por vehículo (SOAT) y por chofer (licencia escaneada). ──
        foreach (array_slice($vehicleIds, 0, 4) as $vehicleId) {
            FleetDocumentFactory::new()
                ->forOwner(DocumentOwnerType::Vehicle, (string) $vehicleId)
                ->withFile()
                ->create();
        }
        foreach (array_slice($driverIds, 0, 3) as $driverId) {
            FleetDocumentFactory::new()
                ->forOwner(DocumentOwnerType::Driver, (string) $driverId)
                ->create();
        }
        // Un documento vencido para poblar las alertas de vencimiento.
        if ($helperIds !== []) {
            FleetDocumentFactory::new()
                ->forOwner(DocumentOwnerType::Helper, (string) $helperIds[0])
                ->expired()
                ->create();
        }
    }

    /**
     * IDs de nodos reales de Network para usar como base de la flota; si Network está vacío, cae en un
     * UUID generado para no romper el seed (mismo criterio tolerante que las factories).
     *
     * @return non-empty-list<string>
     */
    private function homeBaseNodeIds(): array
    {
        /** @var list<string> $ids */
        $ids = NodeRecord::query()->orderBy('code')->pluck('id')->all();

        return $ids === [] ? [Uuid::generate()->value] : $ids;
    }
}
