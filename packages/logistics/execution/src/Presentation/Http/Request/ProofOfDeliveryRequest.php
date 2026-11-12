<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Closure;
use Illuminate\Validation\Validator;
use Logistics\Execution\Application\Contract\DeliveryEvidenceReader;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * Validación de la prueba de entrega (confirmación + foto OBLIGATORIA ya subida).
 *
 * - Foto: toda entrega se cierra con foto, sin excepción. Se acepta `photo_path` (la referencia durable que
 *   devuelve POST /app/deliveries/photos) o `photo_paths:[…]` (varias fotos ya subidas). Cada referencia
 *   DEBE existir en su disco de evidencia: un texto cualquiera ("pendiente") → 422 en `photo_path`. Sin
 *   foto, la cubren `evidence_ids` que apunten a evidencia ya capturada del envío.
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
    /**
     * Mensaje de la foto que no salió de la subida (la app lo muestra tal cual).
     */
    public const string PHOTO_NOT_UPLOADED = 'The photo was not uploaded. Upload it first with POST /app/deliveries/photos.';

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
            // Sin foto, la cubren evidence_ids de evidencia ya capturada (se verifica en after()).
            'photo_path' => ['required_without_all:photo_paths,evidence_ids', 'nullable', 'string', 'max:1024'],
            'photo_paths' => ['sometimes', 'array', 'min:1', 'max:20'],
            'photo_paths.*' => ['required', 'string', 'max:1024'],
            'evidence_ids' => ['sometimes', 'array', 'max:20'],
            'evidence_ids.*' => ['required', 'uuid'],
        ]);
    }

    /**
     * Verificación de que la foto EXISTE (ver verifyPhotoEvidence()).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(EvidenceStorage $storage, DeliveryEvidenceReader $evidence): array
    {
        return [
            static function (Validator $validator) use ($storage, $evidence): void {
                self::verifyPhotoEvidence($validator, $storage, $evidence);
            },
        ];
    }

    /**
     * Verifica que la foto del POD salió de la subida. Corre solo si las reglas de forma pasaron, y la usan
     * por igual el endpoint individual y el ítem `proof_of_delivery` del lote offline (mismas reglas).
     *
     * - Con fotos (`photo_path` / `photo_paths`): cada referencia debe existir en su disco de evidencia; si
     *   alguna no existe → 422 en `photo_path`. Si el almacenamiento no responde (no verificable) se acepta:
     *   rechazar ahí haría que la cola offline descarte una entrega REAL.
     * - Sin fotos: al menos un `evidence_ids` debe ser evidencia ya capturada de ESTE envío.
     */
    public static function verifyPhotoEvidence(Validator $validator, EvidenceStorage $storage, DeliveryEvidenceReader $evidence): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = $validator->getData();
        $paths = self::photoReferences($data);

        if ($paths === []) {
            if (!self::referencesStoredEvidence($data, $evidence)) {
                $validator->errors()->add('photo_path', self::PHOTO_NOT_UPLOADED);
            }

            return;
        }

        foreach ($paths as $path) {
            if ($storage->exists($path) === false) {
                $validator->errors()->add('photo_path', self::PHOTO_NOT_UPLOADED);

                return;
            }
        }
    }

    /**
     * Referencias de foto del cuerpo (`photo_path` + `photo_paths`), recortadas y sin vacíos ni repetidos.
     *
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    private static function photoReferences(array $data): array
    {
        $candidates = [$data['photo_path'] ?? null];
        $many = $data['photo_paths'] ?? null;
        if (is_array($many)) {
            array_push($candidates, ...array_values($many));
        }

        $paths = [];
        foreach ($candidates as $candidate) {
            $trimmed = is_string($candidate) ? trim($candidate) : '';
            if ($trimmed !== '' && !in_array($trimmed, $paths, true)) {
                $paths[] = $trimmed;
            }
        }

        return $paths;
    }

    /**
     * ¿Alguno de los `evidence_ids` es evidencia ya capturada (POST /app/deliveries/evidence) del envío?
     *
     * @param array<string, mixed> $data
     */
    private static function referencesStoredEvidence(array $data, DeliveryEvidenceReader $evidence): bool
    {
        $ids = $data['evidence_ids'] ?? null;
        $shipmentId = $data['shipment_id'] ?? null;
        if (!is_array($ids) || $ids === [] || !is_string($shipmentId)) {
            return false;
        }

        $stored = array_map(
            static fn (DeliveryEvidenceView $view): string => strtolower($view->id),
            $evidence->forShipment($shipmentId),
        );

        foreach ($ids as $id) {
            if (is_string($id) && in_array(strtolower(trim($id)), $stored, true)) {
                return true;
            }
        }

        return false;
    }
}
