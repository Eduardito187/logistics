<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Network\Database\Factories\LegFactory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fila de la tabla network_legs: un tramo/corredor entre dos nodos.
 *
 * El contexto todavía no tiene agregado ni casos de uso para tramos (solo la tabla y el enum
 * CorridorType); este record existe para que el admin Filament pueda administrar los tramos como
 * datos maestros. Cuando el tramo gane dominio, el admin pasará a despachar comandos por el bus.
 *
 * La pk es un UUID (string, no autoincremental).
 *
 * @property string $id
 * @property string $from_node_id
 * @property string $to_node_id
 * @property string $corridor_type
 */
final class LegRecord extends Model
{
    /** @use HasFactory<LegFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'network_legs';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'from_node_id',
        'to_node_id',
        'corridor_type',
    ];

    /**
     * Enlaza el record con su factory del package (las factories viven en database/factories).
     *
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return LegFactory::new();
    }

    /**
     * Al crear el tramo desde el admin se genera su UUID v7 si no vino uno.
     */
    protected static function booted(): void
    {
        self::creating(function (self $leg): void {
            if ((string) $leg->id === '') {
                $leg->id = Uuid::generate()->value;
            }
        });
    }

    /**
     * Nodo de origen del tramo.
     *
     * @return BelongsTo<NodeRecord, $this>
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(NodeRecord::class, 'from_node_id');
    }

    /**
     * Nodo de destino del tramo.
     *
     * @return BelongsTo<NodeRecord, $this>
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(NodeRecord::class, 'to_node_id');
    }
}
