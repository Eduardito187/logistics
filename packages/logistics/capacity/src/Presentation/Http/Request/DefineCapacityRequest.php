<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Request;

/**
 * Alta de un cupo: clave natural del bucket más el total de capacidad a asignar.
 */
final class DefineCapacityRequest extends BucketIdentityRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->identityRules() + [
            'total' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function total(): float
    {
        return (float) $this->input('total');
    }
}
