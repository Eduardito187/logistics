<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\AlertFactory;

/**
 * Modelo Eloquent de la tabla `telemetry_alerts`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. Las fechas se guardan
 * como texto ISO 8601 (ATOM) y el mapper las reconstruye a DateTimeImmutable, para no acoplar el
 * dominio a Carbon.
 *
 * @property string               $id
 * @property string               $vehicle_id
 * @property string|null          $driver_id
 * @property string               $type
 * @property string               $severity
 * @property float|null           $lat
 * @property float|null           $lng
 * @property array<string, mixed> $detail
 * @property string               $occurred_at
 * @property string|null          $acknowledged_at
 * @property string|null          $acknowledged_by
 */
final class AlertRecord extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $table = 'telemetry_alerts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver AlertFactory).
     */
    protected static function newFactory(): AlertFactory
    {
        return AlertFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'detail' => 'array',
        ];
    }
}
