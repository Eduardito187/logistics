<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Query\GetPromotion;

use Commerce\Promotions\Application\ReadModel\PromotionView;
use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una promoción por su id, o null si no existe.
 *
 * @implements QueryHandler<GetPromotion, PromotionView|null>
 */
final readonly class GetPromotionHandler implements QueryHandler
{
    public function __construct(
        private PromotionRepository $promotions,
    ) {}

    public function handle(Query $query): ?PromotionView
    {
        assert($query instanceof GetPromotion);

        $promotion = $this->promotions->findById(PromotionId::fromString($query->promotionId));

        return $promotion !== null ? PromotionView::fromDomain($promotion) : null;
    }
}
