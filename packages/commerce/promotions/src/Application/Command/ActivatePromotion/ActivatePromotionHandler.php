<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\ActivatePromotion;

use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Exception\PromotionsException;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Activa una promoción existente. Idempotente (el agregado no re-graba si ya está activa).
 *
 * @implements CommandHandler<ActivatePromotion>
 */
final readonly class ActivatePromotionHandler implements CommandHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ActivatePromotion);

        $promotion = $this->promotions->findById(PromotionId::fromString($command->promotionId));
        if ($promotion === null) {
            throw PromotionsException::because('La promoción a activar no existe.');
        }

        $promotion->activate();

        $this->promotions->save($promotion);

        $this->recordEvents->record($promotion->releaseEvents(), 'promotions.promotion');
    }
}
