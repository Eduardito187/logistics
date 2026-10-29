<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Contract;

use Commerce\Promotions\Domain\Model\Promotion;
use Commerce\Promotions\Domain\ValueObject\PromotionId;

/**
 * Puerto de persistencia de promociones. La implementación vive en Infrastructure.
 */
interface PromotionRepository
{
    public function nextIdentity(): PromotionId;

    /**
     * Upsert de la promoción por id (incluye sus condiciones).
     */
    public function save(Promotion $promotion): void;

    public function findById(PromotionId $id): ?Promotion;

    /**
     * Busca un cupón por su código normalizado (mayúsculas sin espacios externos).
     */
    public function findByCode(string $code): ?Promotion;

    /**
     * @return list<Promotion>
     */
    public function all(): array;

    /**
     * Promociones en estado Active (sin filtrar por condiciones; eso lo resuelve el dominio).
     *
     * @return list<Promotion>
     */
    public function listActive(): array;
}
