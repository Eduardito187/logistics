<?php

declare(strict_types=1);

namespace Logistics\Network\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeType;

/**
 * Seeder del contexto network: nodos reales de ejemplo (CDs, tiendas y puntos de recojo) de la red
 * Dismac en La Paz, Santa Cruz y Cochabamba, más los tramos/corredores que los conectan.
 *
 * Inserta a través de los repositorios del dominio (no por SQL crudo) para que la columna geográfica
 * PostGIS quede poblada igual que en producción y para pasar por las mismas validaciones de negocio.
 * Es idempotente: si el nodo (por código) ya existe no lo duplica, pero sí reafirma su departamento
 * (backfill estable); si el tramo (por extremos + corredor) ya existe no lo duplica. No regenera UUIDs.
 *
 * Los corredores que se modelan como tramos son los cuatro que unen dos nodos (CdToCd, CdToStore,
 * StoreToStore, StoreToCd). Los dos corredores terminales —CdToCustomer (última milla desde un CD) y
 * StorePickup (recojo en tienda)— no son tramos: los ofrece cada nodo por sí mismo y los deriva la
 * consulta de corredores operativos por departamento. Así quedan cubiertos los seis tipos.
 */
final class NetworkSeeder extends Seeder
{
    public function run(): void
    {
        /** @var NodeRepository $nodes */
        $nodes = $this->container->make(NodeRepository::class);
        /** @var LegRepository $legs */
        $legs = $this->container->make(LegRepository::class);

        // Catálogo de nodos: [código, nombre, tipo, departamento, lat, lng].
        $catalog = [
            ['CD-LP-01', 'Centro de Distribución El Alto', NodeType::DistributionCenter, Department::LaPaz, -16.5110, -68.1930],
            ['CD-SCZ-01', 'Centro de Distribución Santa Cruz', NodeType::DistributionCenter, Department::SantaCruz, -17.7490, -63.1540],
            ['CD-CBBA-01', 'Centro de Distribución Cochabamba', NodeType::DistributionCenter, Department::Cochabamba, -17.3895, -66.1568],
            ['T-LP-01', 'Tienda La Paz Centro', NodeType::Store, Department::LaPaz, -16.4990, -68.1330],
            ['T-LP-02', 'Tienda Zona Sur', NodeType::Store, Department::LaPaz, -16.5420, -68.0860],
            ['T-SCZ-01', 'Tienda Santa Cruz Norte', NodeType::Store, Department::SantaCruz, -17.7310, -63.1710],
            ['T-CBBA-01', 'Tienda Cochabamba Norte', NodeType::Store, Department::Cochabamba, -17.3650, -66.1700],
            ['PK-LP-01', 'Punto de Recojo Miraflores', NodeType::PickupPoint, Department::LaPaz, -16.5020, -68.1160],
            ['PK-SCZ-01', 'Punto de Recojo Equipetrol', NodeType::PickupPoint, Department::SantaCruz, -17.7640, -63.1980],
        ];

        /** @var array<string, Node> $byCode */
        $byCode = [];
        foreach ($catalog as [$code, $name, $type, $department, $lat, $lng]) {
            $nodeCode = NodeCode::fromString($code);
            $existing = $nodes->findByCode($nodeCode);
            if ($existing !== null) {
                // Backfill idempotente del departamento sobre nodos ya sembrados (no regenera el UUID).
                if ($existing->department() !== $department) {
                    $existing->assignDepartment($department);
                    $nodes->save($existing);
                    $existing->releaseEvents();
                }
                $byCode[$code] = $existing;

                continue;
            }

            $node = Node::register(
                $nodes->nextIdentity(),
                $nodeCode,
                $name,
                $type,
                GeoPoint::of($lat, $lng),
                $department,
            );
            $nodes->save($node);
            $node->releaseEvents();
            $byCode[$code] = $node;
        }

        // Corredores entre nodos (los cuatro tipos que unen dos nodos de la red).
        $corridors = [
            // CD → tienda (reposición).
            ['CD-LP-01', 'T-LP-01', CorridorType::CdToStore],
            ['CD-LP-01', 'T-LP-02', CorridorType::CdToStore],
            ['CD-SCZ-01', 'T-SCZ-01', CorridorType::CdToStore],
            ['CD-CBBA-01', 'T-CBBA-01', CorridorType::CdToStore],
            // CD → CD (traspaso entre centros).
            ['CD-LP-01', 'CD-CBBA-01', CorridorType::CdToCd],
            ['CD-CBBA-01', 'CD-SCZ-01', CorridorType::CdToCd],
            // Tienda → tienda (traspaso entre tiendas).
            ['T-LP-01', 'T-LP-02', CorridorType::StoreToStore],
            // Tienda → CD (devolución / consolidación).
            ['T-SCZ-01', 'CD-SCZ-01', CorridorType::StoreToCd],
        ];

        foreach ($corridors as [$fromCode, $toCode, $corridor]) {
            if (!isset($byCode[$fromCode], $byCode[$toCode])) {
                continue;
            }

            $fromNode = $byCode[$fromCode];
            $toNode = $byCode[$toCode];

            if ($legs->findByEndpoints($fromNode->id(), $toNode->id(), $corridor) !== null) {
                continue;
            }

            $leg = Leg::define(
                $legs->nextIdentity(),
                $fromNode->id(),
                $fromNode->type(),
                $toNode->id(),
                $toNode->type(),
                $corridor,
            );
            $legs->save($leg);
            $leg->releaseEvents();
        }
    }
}
