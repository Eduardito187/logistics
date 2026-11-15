<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\NetworkNodeLookup;
use Throwable;

/**
 * Busca el id de un nodo de red por su código (`network_nodes.code`, único) en solo lectura, por el query
 * builder (sin acoplar el dominio de `network`). La comparación ignora mayúsculas/espacios para tolerar
 * cómo cada fuente escribe el código de la tienda.
 */
final readonly class DbNetworkNodeLookup implements NetworkNodeLookup
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function idForCode(string $code): ?string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        try {
            $id = $this->connection->table('network_nodes')
                ->whereRaw('UPPER(code) = ?', [$normalized])
                ->value('id');
        } catch (Throwable) {
            return null;
        }

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    public function existingId(string $nodeId): ?string
    {
        // Nunca se compara contra la columna uuid un valor que no tenga forma de UUID (PostgreSQL: 22P02).
        $normalized = strtolower(trim($nodeId));
        if (preg_match(self::UUID_PATTERN, $normalized) !== 1) {
            return null;
        }

        try {
            $exists = $this->connection->table('network_nodes')->where('id', $normalized)->exists();
        } catch (Throwable) {
            return null;
        }

        return $exists ? $normalized : null;
    }
}
