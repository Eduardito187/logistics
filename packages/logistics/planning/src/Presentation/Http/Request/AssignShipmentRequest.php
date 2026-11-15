<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicle;

/**
 * Valida el ajuste manual que agrega (o mueve) un envío al plan/camión indicado.
 *
 * El id del plan viene en la ruta; el cuerpo trae el envío a asignar. `latitude` / `longitude` son
 * opcionales (van juntas): si no vienen, el caso de uso toma la ubicación del propio envío.
 */
final class AssignShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipment_id' => ['required', 'string', 'uuid'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Arma el comando combinando el plan de la ruta con el envío del cuerpo.
     */
    public function toCommand(string $planId): AssignShipmentToVehicle
    {
        /** @var array{shipment_id: string, latitude?: int|float|string|null, longitude?: int|float|string|null} $data */
        $data = $this->validated();

        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $located = is_numeric($latitude) && is_numeric($longitude);

        return new AssignShipmentToVehicle(
            $planId,
            $data['shipment_id'],
            latitude: $located ? (float) $latitude : null,
            longitude: $located ? (float) $longitude : null,
        );
    }
}
