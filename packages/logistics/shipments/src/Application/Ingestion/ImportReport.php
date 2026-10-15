<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Ingestion;

/**
 * Reporte del resultado de importar una planilla de pedidos: el resumen por fila, para que el operador
 * vea exactamente qué entró, qué estaba duplicado y qué falló (y por qué).
 *
 * Solo primitivos: es un modelo de lectura, listo para serializar a JSON o mostrar en el admin.
 */
final readonly class ImportReport
{
    /**
     * @param list<array{row: int, outcome: string, external_id: string|null, shipment_id: string|null, error: string|null}> $rows
     */
    private function __construct(
        public int $imported,
        public int $duplicates,
        public int $failed,
        public array $rows,
    ) {}

    /**
     * @param list<array{row: int, outcome: string, external_id: string|null, shipment_id: string|null, error: string|null}> $rows
     */
    public static function of(array $rows): self
    {
        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        foreach ($rows as $row) {
            match ($row['outcome']) {
                ImportRowOutcome::Imported->value => $imported++,
                ImportRowOutcome::Duplicate->value => $duplicates++,
                default => $failed++,
            };
        }

        return new self($imported, $duplicates, $failed, array_values($rows));
    }

    public function total(): int
    {
        return $this->imported + $this->duplicates + $this->failed;
    }

    /**
     * @return array{total: int, imported: int, duplicates: int, failed: int, rows: list<array{row: int, outcome: string, external_id: string|null, shipment_id: string|null, error: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'imported' => $this->imported,
            'duplicates' => $this->duplicates,
            'failed' => $this->failed,
            'rows' => $this->rows,
        ];
    }
}
