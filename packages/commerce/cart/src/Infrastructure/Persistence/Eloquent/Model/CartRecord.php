<?php

declare(strict_types=1);

namespace Commerce\Cart\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Cart\Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_carts`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (lo genera el dominio). Las líneas se guardan como JSONB en la misma fila
 * (son value objects del agregado, no entidades consultables aparte).
 *
 * @property string                                                                                               $id
 * @property string|null                                                                                          $customer_id
 * @property string                                                                                               $session_id
 * @property string                                                                                               $status
 * @property string                                                                                               $currency
 * @property list<array{sku: string, product_name: string, unit_price_cents: int, qty: int, subtotal_cents: int}> $lines
 */
final class CartRecord extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $table = 'commerce_carts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lines' => 'array',
    ];

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }
}
