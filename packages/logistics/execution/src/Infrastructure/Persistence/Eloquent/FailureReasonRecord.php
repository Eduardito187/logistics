<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `execution_failure_reasons`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado lo
 * hace FailureReasonMapper.
 *
 * @property string      $id
 * @property string      $code
 * @property string      $label
 * @property bool        $active
 * @property bool        $requires_photo
 * @property bool        $requires_note
 * @property string|null $note_label
 * @property int         $sort_order
 */
final class FailureReasonRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_failure_reasons';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
        'requires_photo' => 'boolean',
        'requires_note' => 'boolean',
        'sort_order' => 'integer',
    ];
}
