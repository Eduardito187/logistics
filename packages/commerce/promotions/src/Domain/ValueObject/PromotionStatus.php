<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\ValueObject;

/**
 * Estado de una promoción en su ciclo de vida.
 *
 * - Draft: en edición, no aplica a ninguna evaluación.
 * - Active: vigente; aplica si se cumplen sus condiciones (subtotal, canal, ventana de fechas).
 * - Expired: retirada; no vuelve a aplicar (se conserva el histórico).
 */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
}
