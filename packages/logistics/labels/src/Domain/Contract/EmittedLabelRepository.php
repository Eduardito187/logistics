<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Domain\Model\EmittedLabel;

/**
 * Puerto de persistencia de etiquetas emitidas. El dominio lo define; Eloquent lo implementa.
 *
 * `add()` falla si el `labelId` ya existe: el índice único de la tabla es la garantía dura contra
 * duplicados (ADR 004), incluso frente a concurrencia.
 */
interface EmittedLabelRepository
{
    /**
     * @throws DuplicateLabelException si ya se emitió una etiqueta con el mismo `labelId`
     */
    public function add(EmittedLabel $label): void;

    public function existsByLabelId(string $labelId): bool;

    /**
     * Etiquetas emitidas para un pedido (incluye revocadas), ordenadas por emisión y por línea.
     *
     * @return list<EmittedLabel>
     */
    public function forOrder(string $orderNumber): array;

    /**
     * Solo las etiquetas vigentes (no revocadas) de un pedido, en el mismo orden que forOrder().
     *
     * @return list<EmittedLabel>
     */
    public function activeForOrder(string $orderNumber): array;

    /**
     * Revoca todas las etiquetas vigentes de un pedido (reimpresión / corrección). No borra filas: las
     * marca con `revoked_at` y el motivo, para auditoría. Devuelve los `labelId` efectivamente revocados.
     *
     * @return list<string>
     */
    public function revokeForOrder(string $orderNumber, int $revokedAt, string $reason): array;
}
