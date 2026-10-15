<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la importación del mapa de cobertura en KML.
 *
 * El cuerpo llega como texto XML crudo (text/xml o application/xml); se valida solo que haya un
 * contenido no vacío. La estructura fina del KML y la geometría las valida el dominio
 * (KmlCoverageParser) al reconstruir sus value objects.
 */
final class ImportCoverageKmlRequest extends FormRequest
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
        return [];
    }

    /**
     * Documento KML crudo tomado del cuerpo de la petición.
     */
    public function kml(): string
    {
        return (string) $this->getContent();
    }
}
