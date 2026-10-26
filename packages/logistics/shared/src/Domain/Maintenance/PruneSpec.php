<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Maintenance;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Especificación de una poda de retención sobre una tabla de alta rotación.
 *
 * Describe QUÉ borrar sin acoplarse al motor: una ventana temporal (cada registro anterior a `cutoff` en la
 * columna `timeColumn`) y, opcionalmente, un filtro por estado (`statusColumn IN statusValues`). El
 * adaptador de infraestructura traduce esto a un borrado CHUNKED, idempotente y respetuoso con los
 * índices temporales.
 *
 * Es un value object inmutable (Domain puro): no conoce Eloquent ni la conexión.
 */
final readonly class PruneSpec
{
    /**
     * @param string            $label             Etiqueta legible para el log del resultado (p. ej. "outbox dispatched").
     * @param string            $table             Nombre de la tabla a podar.
     * @param string            $timeColumn        Columna temporal que define la antigüedad (debe estar indexada).
     * @param DateTimeImmutable $cutoff            Se borran las filas estrictamente anteriores a este instante.
     * @param int               $chunkSize         Filas por lote de borrado (acota el bloqueo; >= 1).
     * @param bool              $dryRun            Si es verdadero, solo cuenta y NO borra.
     * @param int               $pauseMicroseconds Pausa entre lotes (micros) para aliviar la carga; 0 = sin pausa.
     * @param string|null       $statusColumn      Columna de estado para acotar la poda; null = sin filtro de estado.
     * @param list<string>      $statusValues      Estados que SÍ se podan (IN). Vacío si no hay filtro de estado.
     */
    public function __construct(
        public string $label,
        public string $table,
        public string $timeColumn,
        public DateTimeImmutable $cutoff,
        public int $chunkSize,
        public bool $dryRun,
        public int $pauseMicroseconds = 0,
        public ?string $statusColumn = null,
        public array $statusValues = [],
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('El tamaño de lote (chunkSize) debe ser >= 1.');
        }
        if ($this->pauseMicroseconds < 0) {
            throw new InvalidArgumentException('La pausa entre lotes no puede ser negativa.');
        }
        if ($this->statusColumn !== null && $this->statusValues === []) {
            throw new InvalidArgumentException('Si se filtra por estado, la lista de estados no puede estar vacía.');
        }
    }
}
