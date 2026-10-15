<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el cuerpo de la importación del mapa de cobertura: un FeatureCollection de GeoJSON.
 *
 * Se valida solo la forma de alto nivel (tipo y presencia de features); la geometría fina y las
 * reglas las valida el dominio al reconstruir sus value objects.
 */
final class ImportCoverageMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La puerta /integrations monta credenciales de servicio vía middleware (lo arma el integrador).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:FeatureCollection'],
            'features' => ['required', 'array'],
            'features.*.type' => ['required', 'string', 'in:Feature'],
            'features.*.geometry' => ['required', 'array'],
            'features.*.properties' => ['required', 'array'],
        ];
    }

    /**
     * FeatureCollection ya validado y decodificado, listo para el comando de dominio.
     *
     * @return array<string, mixed>
     */
    public function featureCollection(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return $data;
    }
}
