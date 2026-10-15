<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposal;
use Logistics\Planning\Application\Command\GeneratePlanProposal\ProposalStopInput;

/**
 * Valida la petición para generar la propuesta automática pedido→camión de un día.
 *
 * La autorización (puerta de la app, roles de planificador) la resuelve el middleware que arma el
 * integrador; acá solo se valida la forma de la entrada.
 */
final class GeneratePlanProposalRequest extends FormRequest
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
            'plan_date' => ['required', 'string', 'date_format:Y-m-d'],
            'vehicle_id' => ['required', 'string', 'uuid'],
            // Hora de salida del depósito en ISO-8601 (p. ej. '2026-10-13T07:30:00-04:00').
            'depart_at' => ['required', 'string', 'date'],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.shipment_id' => ['required', 'string', 'uuid'],
            'stops.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Arma el comando de aplicación a partir de la entrada ya validada.
     */
    public function toCommand(): GeneratePlanProposal
    {
        /** @var array{plan_date: string, vehicle_id: string, depart_at: string, stops: list<array{shipment_id: string, latitude: int|float|string, longitude: int|float|string}>} $data */
        $data = $this->validated();

        $stops = array_map(
            static fn (array $stop): ProposalStopInput => new ProposalStopInput(
                $stop['shipment_id'],
                (float) $stop['latitude'],
                (float) $stop['longitude'],
            ),
            $data['stops'],
        );

        return new GeneratePlanProposal(
            $data['plan_date'],
            $data['vehicle_id'],
            $data['depart_at'],
            $stops,
        );
    }
}
