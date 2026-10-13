<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicle;

/**
 * Valida el ajuste manual que agrega (o mueve) un envío al plan/camión indicado.
 *
 * El id del plan viene en la ruta; el cuerpo trae el envío a asignar.
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
        ];
    }

    /**
     * Arma el comando combinando el plan de la ruta con el envío del cuerpo.
     */
    public function toCommand(string $planId): AssignShipmentToVehicle
    {
        /** @var array{shipment_id: string} $data */
        $data = $this->validated();

        return new AssignShipmentToVehicle($planId, $data['shipment_id']);
    }
}
