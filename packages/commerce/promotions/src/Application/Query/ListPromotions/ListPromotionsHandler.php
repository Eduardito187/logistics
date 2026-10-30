<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Query\ListPromotions;

use Commerce\Promotions\Application\ReadModel\PromotionView;
use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Model\Promotion;
use Commerce\Promotions\Domain\ValueObject\PromotionStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista promociones (todas o filtradas por estado) como ReadModels.
 *
 * @implements QueryHandler<ListPromotions, list<PromotionView>>
 */
final readonly class ListPromotionsHandler implements QueryHandler
{
    public function __construct(
        private PromotionRepository $promotions,
    ) {}

    /**
     * @return list<PromotionView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPromotions);

        $promotions = $this->promotions->all();
        if ($query->status !== null) {
            $status = PromotionStatus::from($query->status);
            $promotions = array_values(array_filter(
                $promotions,
                static fn (Promotion $promotion): bool => $promotion->status() === $status,
            ));
        }

        return array_values(array_map(
            static fn (Promotion $promotion): PromotionView => PromotionView::fromDomain($promotion),
            $promotions,
        ));
    }
}
