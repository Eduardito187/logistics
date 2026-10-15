<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un segmento de calle agregado. Por dentro es un UUID v7 (ver AbstractId).
 *
 * El id es interno: la clave estable del segmento frente a OSRM es `segment_key` (hash de los nodos),
 * no este id. El id solo da identidad al agregado dentro del contexto.
 */
final readonly class StreetSegmentId extends AbstractId {}
