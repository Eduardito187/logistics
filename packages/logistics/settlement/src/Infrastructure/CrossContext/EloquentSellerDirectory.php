<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\CrossContext;

use Illuminate\Support\Facades\DB;
use Logistics\Settlement\Domain\Contract\SellerDirectory;
use Throwable;

/**
 * Resuelve el nombre legible de un seller desde la tabla `sellers` del contexto `sellers`, en modo SOLO
 * LECTURA y EN LOTE (mismo patrón cross-context que `EloquentSellerDispatchReader`). Si la tabla no existe
 * (contexto extraído), degrada a null: el UUID crudo del seller siempre se mantiene en la vista.
 */
final class EloquentSellerDirectory implements SellerDirectory
{
    public function nameById(string $sellerId): ?string
    {
        try {
            $name = DB::table('sellers')->where('id', $sellerId)->value('legal_name');
        } catch (Throwable) {
            return null;
        }

        return is_string($name) ? $name : null;
    }

    public function namesByIds(array $sellerIds): array
    {
        if ($sellerIds === []) {
            return [];
        }

        try {
            /** @var array<string, string> $names */
            $names = DB::table('sellers')
                ->whereIn('id', $sellerIds)
                ->pluck('legal_name', 'id')
                ->all();
        } catch (Throwable) {
            return [];
        }

        return $names;
    }
}
