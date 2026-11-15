<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Query\SuggestTripSequence\SuggestTripSequence;

/**
 * Valida la petición del orden sugerido de un viaje: día, camión, hora de salida (opcional) y los pedidos
 * en el orden en que los tiene la app. Es una consulta: no guarda nada.
 */
final class SuggestTripSequenceRequest extends FormRequest
{
    /** Tope duro de pedidos por petición (el tope de negocio configurable lo aplica el caso de uso). */
    private const MAX_SHIPMENTS = 500;

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
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'vehicle_id' => ['required', 'string', 'uuid'],
            // Hora de salida de la base en ISO-8601; sin ella se usa la hora de salida por defecto del día.
            'depart_at' => ['nullable', 'string', 'date'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SHIPMENTS],
            'shipment_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    public function toQuery(): SuggestTripSequence
    {
        /** @var array{date: string, vehicle_id: string, depart_at?: string|null, shipment_ids: list<string>} $data */
        $data = $this->validated();

        return new SuggestTripSequence(
            date: $data['date'],
            vehicleId: $data['vehicle_id'],
            shipmentIds: array_values($data['shipment_ids']),
            departAt: $data['depart_at'] ?? null,
        );
    }
}
