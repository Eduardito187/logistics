<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Id tipado de un caso de compensación. UUID v7 por dentro (ordenable en el tiempo, índices compactos).
 */
final readonly class CompensationCaseId extends AbstractId {}
