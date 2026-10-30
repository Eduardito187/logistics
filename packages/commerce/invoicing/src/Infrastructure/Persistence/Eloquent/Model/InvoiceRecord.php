<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent de la tabla `commerce_invoices`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string                                                                                        $id
 * @property string                                                                                        $order_id
 * @property string|null                                                                                   $number
 * @property string                                                                                        $customer_name
 * @property string|null                                                                                   $customer_tax_id
 * @property string                                                                                        $currency
 * @property list<array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int}> $lines
 * @property int                                                                                           $subtotal_cents
 * @property int                                                                                           $tax_cents
 * @property int                                                                                           $total_cents
 * @property string                                                                                        $status
 * @property Carbon|null                                                                                   $issued_at
 */
final class InvoiceRecord extends Model
{
    protected $table = 'commerce_invoices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lines' => 'array',
        'issued_at' => 'datetime',
    ];
}
