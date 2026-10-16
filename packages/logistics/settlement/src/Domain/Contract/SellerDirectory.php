<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Contract;

/**
 * Puerto de LECTURA cross-context del nombre legible de un seller (tabla `sellers`, otro contexto).
 *
 * Igual que `SellerDispatchReader`, `settlement` no depende por composer de `sellers`: la implementación
 * (Infrastructure) resuelve los nombres EN LOTE y degrada a null si la tabla no existe (contexto extraído).
 * Es puro enriquecimiento de lectura: el UUID crudo del seller siempre se mantiene.
 */
interface SellerDirectory
{
    public function nameById(string $sellerId): ?string;

    /**
     * @param  list<string>          $sellerIds
     * @return array<string, string> id → nombre legible (solo los encontrados)
     */
    public function namesByIds(array $sellerIds): array;
}
