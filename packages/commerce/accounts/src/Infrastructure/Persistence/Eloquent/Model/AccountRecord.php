<?php

declare(strict_types=1);

namespace Commerce\Accounts\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Accounts\Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_accounts`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (lo genera el dominio). `user_id` referencia al usuario de `access` por UUID.
 * Las direcciones se guardan como JSONB en la misma fila (son value objects del agregado, no entidades
 * consultables aparte en esta fase).
 *
 * @property string                                                                                                                                       $id
 * @property string                                                                                                                                       $type
 * @property string                                                                                                                                       $user_id
 * @property string                                                                                                                                       $display_name
 * @property string|null                                                                                                                                  $tax_id
 * @property string                                                                                                                                       $status
 * @property list<array{label: string, recipient: string, line1: string, line2: ?string, city: string, state: ?string, phone: ?string, is_default: bool}> $addresses
 */
final class AccountRecord extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $table = 'commerce_accounts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'addresses' => 'array',
    ];

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }
}
