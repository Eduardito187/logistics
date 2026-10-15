<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use Logistics\Capacity\Domain\ActiveHorizon;
use Logistics\Capacity\Domain\ValueObject\ActiveHorizonId;

/**
 * Puerto de persistencia del horizonte activo de cupos por alcance (municipio o global). El dominio lo
 * define; la infraestructura (Eloquent) lo implementa. Un registro por municipio y uno global
 * (municipio null).
 */
interface ActiveHorizonRepository
{
    public function nextIdentity(): ActiveHorizonId;

    public function save(ActiveHorizon $horizon): void;

    /**
     * El horizonte del alcance dado (municipio, o global con municipio null). Null si nunca se activó.
     */
    public function findFor(?string $municipalityId): ?ActiveHorizon;
}
