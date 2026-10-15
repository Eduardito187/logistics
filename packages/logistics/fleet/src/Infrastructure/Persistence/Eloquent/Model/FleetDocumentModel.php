<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;

/**
 * Modelo Eloquent de la tabla `fleet_documents`. Detalle de infraestructura: no cruza a Domain.
 *
 * El dueño es polimórfico por valor (`owner_type` + `owner_id`): según el tipo apunta a un vehículo,
 * un chofer o un ayudante. Por eso se exponen tres relaciones `belongsTo` sobre la misma columna
 * `owner_id` y un accesor `ownerName` que resuelve la etiqueta legible según el tipo.
 *
 * @property string      $id
 * @property string      $owner_type
 * @property string      $owner_id
 * @property string      $document_type
 * @property string      $expires_on
 * @property string|null $issued_on
 * @property string|null $file_path
 * @property string|null $note
 * @property string|null $reference
 * @property-read VehicleModel|null $ownerVehicle
 * @property-read DriverModel|null  $ownerDriver
 * @property-read HelperModel|null  $ownerHelper
 * @property-read string|null       $ownerName
 */
final class FleetDocumentModel extends Model
{
    protected $table = 'fleet_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `expires_on` e `issued_on` se dejan sin cast: Postgres devuelve la fecha como texto 'Y-m-d'.
        return [];
    }

    /**
     * Dueño cuando es un vehículo. Solo aplica si `owner_type` es `vehicle`.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function ownerVehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'owner_id');
    }

    /**
     * Dueño cuando es un chofer. Solo aplica si `owner_type` es `driver`.
     *
     * @return BelongsTo<DriverModel, $this>
     */
    public function ownerDriver(): BelongsTo
    {
        return $this->belongsTo(DriverModel::class, 'owner_id');
    }

    /**
     * Dueño cuando es un ayudante. Solo aplica si `owner_type` es `helper`.
     *
     * @return BelongsTo<HelperModel, $this>
     */
    public function ownerHelper(): BelongsTo
    {
        return $this->belongsTo(HelperModel::class, 'owner_id');
    }

    /**
     * Etiqueta legible del dueño (placa o nombre) resuelta desde la relación ya cargada según el tipo.
     *
     * Usa las relaciones eager-cargadas (no dispara consultas nuevas si se hizo `->with(...)`).
     */
    public function getOwnerNameAttribute(): ?string
    {
        return match ($this->owner_type) {
            DocumentOwnerType::Vehicle->value => $this->ownerVehicle?->plate,
            DocumentOwnerType::Driver->value => $this->ownerDriver?->name,
            DocumentOwnerType::Helper->value => $this->ownerHelper?->name,
            default => null,
        };
    }
}
