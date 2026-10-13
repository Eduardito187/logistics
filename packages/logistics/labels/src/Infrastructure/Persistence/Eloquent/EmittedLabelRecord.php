<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla labels_emitted. Detalle de infraestructura: el dominio no lo conoce, solo lo usa el
 * repositorio Eloquent para mapear hacia y desde EmittedLabel.
 *
 * La pk es un uuid propio (string, no autoincremental), independiente del `label_id` de negocio. El
 * `label_id` (campo `id` del payload) lleva índice único: es la detección dura de duplicados.
 *
 * @property string $id
 * @property string $order_id
 * @property string $label_id
 * @property int $key_id
 * @property string $zpl
 * @property string $signed_json
 * @property string $created_at
 */
final class EmittedLabelRecord extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'labels_emitted';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'order_id',
        'label_id',
        'key_id',
        'zpl',
        'signed_json',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'key_id' => 'integer',
    ];
}
