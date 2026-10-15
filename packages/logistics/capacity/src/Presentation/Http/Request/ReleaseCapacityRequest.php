<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Request;

/**
 * Liberación de cupo: clave natural del bucket más la cantidad a devolver (debe ser positiva).
 */
final class ReleaseCapacityRequest extends BucketIdentityRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->identityRules() + [
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function amount(): float
    {
        return (float) $this->input('amount');
    }
}
