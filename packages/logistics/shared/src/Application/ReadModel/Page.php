<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\ReadModel;

/**
 * Página de un listado de LECTURA (CQRS): los ítems YA recortados por SQL (LIMIT/OFFSET) más el total
 * de filas que cumplen el filtro (COUNT(*) aparte, sin recorrer toda la tabla).
 *
 * Es el contrato uniforme que devuelve un QueryHandler cuando se le pide una página concreta. La capa
 * de Presentación arma el envelope `data` + `meta` a partir de esto (ver App\Http\Support\ApiPagination);
 * así el dominio/aplicación no conocen el formato HTTP de la paginación.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items ítems de ESTA página (ya proyectados a ReadModel), no la tabla completa
     * @param int     $total total de filas que cumplen el filtro (para calcular páginas/next_cursor)
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {}
}
