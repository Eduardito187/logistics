<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\CreatePromotion;

use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Model\Promotion;
use Commerce\Promotions\Domain\ValueObject\PromotionConditions;
use Commerce\Promotions\Domain\ValueObject\PromotionType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye la promoción en borrador, la persiste y publica sus eventos al outbox (misma transacción que
 * abre el decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<CreatePromotion>
 */
final readonly class CreatePromotionHandler implements CommandHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CreatePromotion);

        $promotion = Promotion::create(
            $this->promotions->nextIdentity(),
            $command->name,
            $command->code,
            PromotionType::from($command->type),
            $command->value,
            $command->currency,
            PromotionConditions::of(
                $command->minSubtotalCents,
                $command->channel,
                $command->startsAt,
                $command->endsAt,
            ),
        );

        $this->promotions->save($promotion);

        $this->recordEvents->record($promotion->releaseEvents(), 'promotions.promotion');
    }
}
