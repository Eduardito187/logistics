<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\ExpirePromotion;

use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Exception\PromotionsException;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Expira una promoción existente. Idempotente (el agregado no re-graba si ya está expirada).
 *
 * @implements CommandHandler<ExpirePromotion>
 */
final readonly class ExpirePromotionHandler implements CommandHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ExpirePromotion);

        $promotion = $this->promotions->findById(PromotionId::fromString($command->promotionId));
        if ($promotion === null) {
            throw PromotionsException::because('La promoción a expirar no existe.');
        }

        $promotion->expire();

        $this->promotions->save($promotion);

        $this->recordEvents->record($promotion->releaseEvents(), 'promotions.promotion');
    }
}
