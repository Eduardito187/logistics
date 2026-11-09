<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Nota o seguimiento (actividad CRM) adjunto a una entidad del admin (cliente, pedido, envío, vehículo…).
 * Preferencia/actividad humana, no dato de negocio; por eso vive como modelo Eloquent simple en la capa de
 * app (no en un bounded context). Polimórfica por (subject_type, subject_id) en texto.
 *
 * @property int         $id
 * @property int|null    $author_id
 * @property string|null $author_label
 * @property string      $subject_type
 * @property string      $subject_id
 * @property string      $body
 * @property string      $kind         'note' | 'task'
 * @property Carbon|null $due_on
 * @property Carbon|null $done_at
 * @property Carbon|null $created_at
 */
class CockpitNote extends Model
{
    /** Tipos de nota: anotación simple vs. tarea con vencimiento. */
    public const string KIND_NOTE = 'note';

    public const string KIND_TASK = 'task';

    protected $table = 'cockpit_notes';

    /** @var list<string> */
    protected $fillable = ['author_id', 'author_label', 'subject_type', 'subject_id', 'body', 'kind', 'due_on', 'done_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }

    /** ¿Es una tarea pendiente (kind=task y sin marcar hecha)? */
    public function isOpenTask(): bool
    {
        return $this->kind === self::KIND_TASK && $this->done_at === null;
    }
}
