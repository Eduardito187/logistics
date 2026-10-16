<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Fulfillment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Logistics\Promise\Domain\Contract\FulfillmentLeadTimeProvider;
use Logistics\Promise\Domain\ValueObject\LeadTime;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Lead time adicional POR SELLER, con degradación al default por modo.
 *
 * Decora al {@see ConfigurableFulfillmentLeadTimeProvider}: si el producto es de un seller y su ficha tiene
 * un lead configurado, usa ese valor; si no, cae al default por modo de fulfillment.
 *
 * Lee la tabla `sellers` de SOLO CONSULTA (patrón del monorepo: nada de dependencia composer a `sellers`,
 * solo lectura por tabla, como `NetworkBackedCorridorProvider`). Hoy la tabla `sellers` NO tiene una
 * columna de lead time, por eso la lectura está guardada con `Schema::hasColumn`: mientras no exista, este
 * adaptador se comporta como el default por modo. Cuando `sellers` agregue, p. ej., `fulfillment_lead_days`,
 * este puerto lo respeta sin tocar promise.
 */
final readonly class SellerBackedFulfillmentLeadTimeProvider implements FulfillmentLeadTimeProvider
{
    /** Columna opcional (aún inexistente) en `sellers` con el lead por seller en días. */
    private const string SELLER_LEAD_COLUMN = 'fulfillment_lead_days';

    public function __construct(private FulfillmentLeadTimeProvider $defaults) {}

    public function leadTimeFor(Ownership $ownership): LeadTime
    {
        // Retail (sin dueño) nunca tiene lead por seller: va directo al default (que para OWNED es cero).
        $ownerPartyId = $ownership->ownerPartyId?->value;
        if ($ownerPartyId === null) {
            return $this->defaults->leadTimeFor($ownership);
        }

        $sellerDays = $this->sellerLeadDays($ownerPartyId);
        if ($sellerDays !== null) {
            return LeadTime::ofDays($sellerDays);
        }

        return $this->defaults->leadTimeFor($ownership);
    }

    /**
     * Lead en días configurado para el seller, o null si no hay columna/valor (degradación al default).
     */
    private function sellerLeadDays(string $sellerId): ?int
    {
        if (!DbSchema::hasColumn('sellers', self::SELLER_LEAD_COLUMN)) {
            return null;
        }

        $value = DB::table('sellers')
            ->where('id', $sellerId)
            ->value(self::SELLER_LEAD_COLUMN);

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        return $days >= 0 ? $days : null;
    }
}
