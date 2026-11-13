<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Orders\Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent de la tabla `commerce_orders`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (lo genera el dominio). Dirección de entrega y líneas se guardan como
 * JSONB (value objects del agregado).
 *
 * @property string                                                                                                 $id
 * @property string|null                                                                                            $customer_id
 * @property string                                                                                                 $status
 * @property string                                                                                                 $source
 * @property string|null                                                                                            $order_reference
 * @property string                                                                                                 $currency
 * @property bool                                                                                                   $shipment_requested
 * @property string                                                                                                 $financing
 * @property bool                                                                                                   $contract_signed
 * @property bool                                                                                                   $initial_paid
 * @property array{customer_name: string, address_text: string, reference: ?string, phone: ?string, email: ?string} $ship_to
 * @property list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}>                   $lines
 * @property Carbon|null                                                                                            $created_at
 * @property Carbon|null                                                                                            $updated_at
 */
final class OrderRecord extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $table = 'commerce_orders';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ship_to' => 'array',
        'lines' => 'array',
        'shipment_requested' => 'boolean',
        'contract_signed' => 'boolean',
        'initial_paid' => 'boolean',
    ];

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
