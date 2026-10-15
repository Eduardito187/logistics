<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Request;

/**
 * Consulta del disponible de un cupo (parámetros por query string). Solo la clave natural del bucket.
 */
final class AvailableCapacityRequest extends BucketIdentityRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->identityRules();
    }
}
