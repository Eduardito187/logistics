<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

/**
 * Nivel de aprobación requerido según el monto efectivo y la política (§2.1.5).
 *
 * AUTO: lo aprueba el sistema (monto bajo y disparador con auto-aprobación).
 * LEVEL1: supervisor (permiso compensation.approve.level1).
 * LEVEL2: gerencia (permiso compensation.approve.level2).
 */
enum ApprovalLevel: string
{
    case Auto = 'AUTO';
    case Level1 = 'LEVEL1';
    case Level2 = 'LEVEL2';

    /**
     * Decide el nivel a partir del monto efectivo, los umbrales y si el disparador admite auto-aprobación.
     */
    public static function decide(
        Money $effective,
        Money $autoApproveMax,
        Money $level1Max,
        bool $autoApproveEligible,
    ): self {
        if ($autoApproveEligible && $effective->cents <= $autoApproveMax->cents) {
            return self::Auto;
        }

        if ($effective->cents <= $level1Max->cents) {
            return self::Level1;
        }

        return self::Level2;
    }
}
