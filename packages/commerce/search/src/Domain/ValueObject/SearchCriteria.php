<?php

declare(strict_types=1);

namespace Commerce\Search\Domain\ValueObject;

use Commerce\Search\Domain\Exception\SearchException;

/**
 * Criterio inmutable de una búsqueda de catálogo. VO de dominio (PHP puro: sin Laravel ni Eloquent).
 *
 * Centraliza y valida las reglas del lado de lectura antes de tocar la base:
 *  - `term`: texto libre normalizado (null = sin filtro de texto, solo navegación).
 *  - `categoryId`: filtra por categoría (null = todas).
 *  - `status`: estado de publicación; por defecto `published` (lo que ve el storefront).
 *  - `limit`: tamaño de página, acotado a 1..100 (tope duro para no barrer de más).
 *  - `cursor`: cursor opaco de paginación keyset (null = primera página). Su codificación es un detalle
 *    de infraestructura; el dominio solo lo transporta como string opaco.
 */
final readonly class SearchCriteria
{
    /** Estados de publicación admitidos (espejo del catálogo; no se acopla a su enum). */
    public const ALLOWED_STATUSES = ['draft', 'published', 'archived'];

    public const DEFAULT_STATUS = 'published';

    public const MIN_LIMIT = 1;

    public const MAX_LIMIT = 100;

    public const DEFAULT_LIMIT = 20;

    private function __construct(
        public ?string $term,
        public ?string $categoryId,
        public string $status,
        public int $limit,
        public ?string $cursor,
    ) {}

    public static function of(
        ?string $term = null,
        ?string $categoryId = null,
        string $status = self::DEFAULT_STATUS,
        int $limit = self::DEFAULT_LIMIT,
        ?string $cursor = null,
    ): self {
        $term = self::normalizeNullable($term);
        $categoryId = self::normalizeNullable($categoryId);
        $cursor = self::normalizeNullable($cursor);

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw SearchException::because(
                sprintf('Estado de búsqueda no permitido: "%s".', $status),
            );
        }

        if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw SearchException::because(
                sprintf('El límite debe estar entre %d y %d.', self::MIN_LIMIT, self::MAX_LIMIT),
            );
        }

        return new self($term, $categoryId, $status, $limit, $cursor);
    }

    /**
     * ¿La búsqueda incluye filtro de texto libre (FTS) o es solo navegación por filtros?
     */
    public function hasTerm(): bool
    {
        return $this->term !== null;
    }

    /**
     * Recorta espacios y trata la cadena vacía como ausencia de valor.
     */
    private static function normalizeNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
