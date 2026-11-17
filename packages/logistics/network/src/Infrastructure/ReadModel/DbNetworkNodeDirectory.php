<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Network\Application\Contract\NetworkNodeDirectory;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;
use stdClass;

/**
 * Directorio de nodos sobre `network_nodes` con el query builder: lecturas puntuales de las columnas
 * necesarias, sin hidratar agregados. La red tiene pocos nodos, así que cada consulta es barata.
 *
 * Tolerante: cada método devuelve null cuando no hay un nodo que cumpla.
 */
final readonly class DbNetworkNodeDirectory implements NetworkNodeDirectory
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function departmentOf(string $nodeId): ?string
    {
        $row = $this->connections->connection()->table('network_nodes')
            ->where('id', $nodeId)
            ->first(['department']);

        if (!$row instanceof stdClass || $row->department === null) {
            return null;
        }

        // Normaliza a código canónico (`santa_cruz`, …); null si el valor guardado no se reconoce.
        return Department::tryFromText((string) $row->department)?->value;
    }

    public function hubOfDepartment(string $department): ?string
    {
        $code = Department::tryFromText($department)?->value;
        if ($code === null) {
            return null;
        }

        $row = $this->connections->connection()->table('network_nodes')
            ->where('status', NodeStatus::Active->value)
            ->where('department', $code)
            ->whereIn('type', [NodeType::DistributionCenter->value, NodeType::CentralWarehouse->value])
            ->orderBy('code')
            ->first(['id']);

        return $row instanceof stdClass ? (string) $row->id : null;
    }

    public function carrierHub(?string $carrierId): ?string
    {
        // La red todavía no vincula nodos a transportadoras: se toma el primer carrier_hub activo por
        // código. El $carrierId queda reservado para cuando exista esa relación.
        $row = $this->connections->connection()->table('network_nodes')
            ->where('status', NodeStatus::Active->value)
            ->where('type', NodeType::CarrierHub->value)
            ->orderBy('code')
            ->first(['id']);

        return $row instanceof stdClass ? (string) $row->id : null;
    }
}
