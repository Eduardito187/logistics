<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * Validación de la prueba de entrega (confirmación + foto OBLIGATORIA ya subida).
 *
 * - Foto: toda entrega se cierra con foto, sin excepción. Se acepta `photo_path` (la referencia durable que
 *   devuelve POST /app/deliveries/photos) o `photo_paths:[…]` (varias fotos ya subidas).
 * - Bultos: `unit_id` (uno) o `unit_ids:[…]` (POD en lote, hasta 500, sin repetidos).
 * - GPS OPCIONAL: `lat`/`lng` nullable, o `location:{lat,lng,accuracy_m,captured_at}`. Sin señal no se manda
 *   nada (0,0 se descarta en el dominio: nunca se guarda como posición real).
 * - `confirmation`: requerida, salvo para el TRANSPORTISTA externo y el MOSTRADOR de tienda (retiro en
 *   tienda): ninguno necesita el QR del cliente (marcadores CARRIER_HANDOVER / STORE_PICKUP_HANDOVER).
 * - `evidence_ids:[…]`: ids de evidencia anti-fraude (POST /app/deliveries/evidence) que respaldan el POD.
 *
 * En POST /app/deliveries/{shipmentId}/complete el envío viaja en la ruta y se copia al cuerpo.
 */
final class ProofOfDeliveryRequest extends ScanRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $routeShipment = $this->route('shipmentId');
        if (is_string($routeShipment) && trim($routeShipment) !== '') {
            $this->merge(['shipment_id' => trim($routeShipment)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'unit_id' => ['required_without:unit_ids', 'nullable', 'string', 'max:64'],
            'unit_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'unit_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            // GPS opcional (#25): null = sin señal.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'location' => ['sometimes', 'nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'location.captured_at' => ['nullable', 'date'],
            // Transportista externo y mostrador de tienda (retiro en tienda) no exigen el QR del cliente.
            'confirmation' => ScanCommandFactory::isCarrier($this->user()) || ScanCommandFactory::isStoreCounter($this->user())
                ? ['nullable', 'string', 'max:512']
                : ['required', 'string', 'max:512'],
            'photo_url' => ['nullable', 'string', 'url', 'max:2048'],
            // La foto de entrega es OBLIGATORIA SIEMPRE: falta → 422 (validation_failed), nunca un cierre sin foto.
            'photo_path' => ['required_without:photo_paths', 'nullable', 'string', 'max:1024'],
            'photo_paths' => ['sometimes', 'array', 'min:1', 'max:20'],
            'photo_paths.*' => ['required', 'string', 'max:1024'],
            'evidence_ids' => ['sometimes', 'array', 'max:20'],
            'evidence_ids.*' => ['required', 'uuid'],
        ]);
    }
}
