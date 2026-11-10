<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una evidencia de entrega ligada al cliente (firma, documento, POD, daño). UUID v7.
 */
final readonly class DeliveryEvidenceId extends AbstractId {}
