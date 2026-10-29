<?php

declare(strict_types=1);

namespace Commerce\Promotions\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Promotions\Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_promotions`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (lo genera el dominio). Las condiciones se guardan como JSONB en la misma
 * fila (son un value object del agregado, no una entidad consultable aparte).
 *
 * @property string                                                                                 $id
 * @property string                                                                                 $name
 * @property string|null                                                                            $code
 * @property string                                                                                 $type
 * @property int                                                                                    $value
 * @property string                                                                                 $currency
 * @property array{min_subtotal_cents: int, channel: ?string, starts_at: ?string, ends_at: ?string} $conditions
 * @property string                                                                                 $status
 * @property int                                                                                    $version
 */
final class PromotionRecord extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    protected $table = 'commerce_promotions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'conditions' => 'array',
        'value' => 'integer',
        'version' => 'integer',
    ];

    protected static function newFactory(): PromotionFactory
    {
        return PromotionFactory::new();
    }
}
