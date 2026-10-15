<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Labels\Database\Factories\EmittedLabelRecordFactory;

/**
 * Fila de la tabla labels_emitted. Detalle de infraestructura: el dominio no lo conoce, solo lo usa el
 * repositorio Eloquent para mapear hacia y desde EmittedLabel.
 *
 * La pk es un uuid propio (string, no autoincremental), independiente del `label_id` de negocio. El
 * `label_id` (campo `id` del payload) lleva índice único: es la detección dura de duplicados. Una
 * etiqueta revocada (reimpresión) conserva su fila con `revoked_at` y `revoked_reason`.
 *
 * @property string      $id
 * @property string      $order_id
 * @property string      $label_id
 * @property int         $key_id
 * @property string      $zpl
 * @property string      $signed_json
 * @property string      $created_at
 * @property string|null $revoked_at
 * @property string|null $revoked_reason
 */
final class EmittedLabelRecord extends Model
{
    /** @use HasFactory<EmittedLabelRecordFactory> */
    use HasFactory;

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
        'revoked_at',
        'revoked_reason',
    ];

    // No se castea created_at/revoked_at a datetime a propósito: el repositorio Eloquent los lee como
    // string ISO-8601 (new DateTimeImmutable($record->created_at)); castearlos a Carbon rompería el
    // mapeo a dominio. Filament formatea igualmente el string con ->dateTime().
    /** @var array<string, string> */
    protected $casts = [
        'key_id' => 'integer',
    ];

    /**
     * Decodificación memoizada del JSON firmado del QR.
     *
     * @var array<string, mixed>|null
     */
    private ?array $decodedPayload = null;

    /**
     * @return Factory<EmittedLabelRecord>
     */
    protected static function newFactory(): Factory
    {
        return EmittedLabelRecordFactory::new();
    }

    /**
     * Decodifica una sola vez el contenido firmado del QR para exponer campos legibles (SKU, línea,
     * fracciones "N de M", ruta). NO valida la firma: es solo para mostrar datos humanos en el admin,
     * no para confiar en ellos. Si el JSON está mal formado devuelve un arreglo vacío.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        if ($this->decodedPayload === null) {
            $decoded = json_decode((string) $this->signed_json, true);
            $this->decodedPayload = is_array($decoded) ? $decoded : [];
        }

        return $this->decodedPayload;
    }

    /** SKU del producto etiquetado (campo `sku` del QR), legible en tabla y detalle. */
    public function getSkuAttribute(): ?string
    {
        $value = $this->payload()['sku'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Número de línea del pedido (campo `ln` del QR). */
    public function getLineNumberAttribute(): ?int
    {
        $value = $this->payload()['ln'] ?? null;

        return is_int($value) ? $value : null;
    }

    /** Unidad "N of M" del producto (campo `u` del QR): qué unidad del mismo SKU es este bulto. */
    public function getUnitLabelAttribute(): ?string
    {
        return self::fractionLabel($this->payload()['u'] ?? null);
    }

    /** Pieza/caja "N of M" dentro de la unidad (campo `pc` del QR). */
    public function getPieceLabelAttribute(): ?string
    {
        return self::fractionLabel($this->payload()['pc'] ?? null);
    }

    /** Nodo de origen del bulto (campo `org` del QR). */
    public function getOriginAttribute(): ?string
    {
        $value = $this->payload()['org'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Destino legible: "Customer" para entregas a cliente final (dst = C) o el código de nodo. */
    public function getDestinationLabelAttribute(): ?string
    {
        $value = $this->payload()['dst'] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return $value === 'C' ? 'Customer' : $value;
    }

    /** True si la etiqueta fue revocada (reimpresión/corrección): ya no es válida. */
    public function getIsRevokedAttribute(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Convierte el par [índice, total] del QR en una etiqueta "N of M", o null si no es válido.
     *
     * @param mixed $fraction
     */
    private static function fractionLabel($fraction): ?string
    {
        if (!is_array($fraction) || !isset($fraction[0], $fraction[1]) || !is_int($fraction[0]) || !is_int($fraction[1])) {
            return null;
        }

        return sprintf('%d of %d', $fraction[0], $fraction[1]);
    }
}
