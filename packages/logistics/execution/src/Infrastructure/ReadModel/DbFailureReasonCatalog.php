<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\FailureReasonCatalog;

/**
 * Lector del catálogo de motivos de entrega fallida contra la tabla `execution_failure_reasons` (read-only).
 *
 * Normaliza el code a MAYÚSCULAS para tolerar que la app lo mande en otra caja. Memoiza las filas por
 * instancia (una sola consulta por request) para que validar un lote de entregas fallidas no golpee la base
 * por ítem.
 */
final class DbFailureReasonCatalog implements FailureReasonCatalog
{
    /**
     * Mapa code → {active, requires_photo, requires_note}, cargado una vez.
     *
     * @var array<string, array{active: bool, requires_photo: bool, requires_note: bool}>|null
     */
    private ?array $catalog = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function isActive(string $code): bool
    {
        $entry = $this->catalog()[$this->normalize($code)] ?? null;

        return $entry !== null && $entry['active'];
    }

    public function requirements(string $code): array
    {
        $entry = $this->catalog()[$this->normalize($code)] ?? null;

        return [
            'requires_photo' => $entry !== null && $entry['requires_photo'],
            'requires_note' => $entry !== null && $entry['requires_note'],
        ];
    }

    /**
     * @return array<string, array{active: bool, requires_photo: bool, requires_note: bool}>
     */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $catalog = [];
        $rows = $this->connection->table('execution_failure_reasons')
            ->get(['code', 'active', 'requires_photo', 'requires_note']);

        foreach ($rows as $row) {
            $catalog[$this->normalize((string) $row->code)] = [
                'active' => (bool) $row->active,
                'requires_photo' => (bool) $row->requires_photo,
                'requires_note' => (bool) $row->requires_note,
            ];
        }

        return $this->catalog = $catalog;
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
