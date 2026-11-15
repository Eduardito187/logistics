<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposal;
use Logistics\Planning\Application\Command\GeneratePlanProposal\ProposalStopInput;

/**
 * Valida la petición para crear el plan (viaje) de un camión en un día.
 *
 * `latitude` / `longitude` de cada parada son OPCIONALES (van juntas): si no vienen, el caso de uso toma
 * la ubicación del propio envío y, si el envío no la tiene, responde 422 con el motivo.
 *
 * `optimize` (opcional, por defecto false): false = se RESPETA el orden recibido (el viaje ya lo ordenó el
 * planificador); true = el motor de rutas reordena las paradas, con la primera como punto de salida.
 *
 * La autorización (puerta de la app, habilidades de planificación) la resuelve el middleware que arma el
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
            'stops.*.shipment_id' => ['required', 'string', 'uuid', 'distinct'],
            'stops.*.latitude' => ['nullable', 'required_with:stops.*.longitude', 'numeric', 'between:-90,90'],
            'stops.*.longitude' => ['nullable', 'required_with:stops.*.latitude', 'numeric', 'between:-180,180'],
            'optimize' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Arma el comando de aplicación a partir de la entrada ya validada.
     */
    public function toCommand(): GeneratePlanProposal
    {
        /** @var array{plan_date: string, vehicle_id: string, depart_at: string, stops: list<array{shipment_id: string, latitude?: int|float|string|null, longitude?: int|float|string|null}>} $data */
        $data = $this->validated();

        $stops = array_map(
            static function (array $stop): ProposalStopInput {
                $latitude = $stop['latitude'] ?? null;
                $longitude = $stop['longitude'] ?? null;
                $located = is_numeric($latitude) && is_numeric($longitude);

                return new ProposalStopInput(
                    $stop['shipment_id'],
                    $located ? (float) $latitude : null,
                    $located ? (float) $longitude : null,
                );
            },
            $data['stops'],
        );

        return new GeneratePlanProposal(
            $data['plan_date'],
            $data['vehicle_id'],
            $data['depart_at'],
            $stops,
            // Por defecto se respeta el orden recibido; `optimize: true` pide el reordenamiento automático.
            keepOrder: !$this->boolean('optimize'),
        );
    }
}
