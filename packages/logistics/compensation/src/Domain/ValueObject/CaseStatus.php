<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

/**
 * Estado del ciclo de vida de un caso de compensación.
 *
 * proposed → approved → issued (feliz) | proposed → rejected | proposed → not_compensable (E < mínimo).
 */
enum CaseStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NotCompensable = 'not_compensable';
    case Issued = 'issued';
}
