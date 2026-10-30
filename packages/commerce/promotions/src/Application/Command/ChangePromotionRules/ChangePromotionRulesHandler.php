<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Command\ChangePromotionRules;

use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Exception\PromotionsException;
use Commerce\Promotions\Domain\ValueObject\PromotionConditions;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Commerce\Promotions\Domain\ValueObject\PromotionType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cambia las reglas de una promoción existente (incrementa su versión) y publica el evento al outbox.
 *
 * @implements CommandHandler<ChangePromotionRules>
 */
final readonly class ChangePromotionRulesHandler implements CommandHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ChangePromotionRules);

        $promotion = $this->promotions->findById(PromotionId::fromString($command->promotionId));
        if ($promotion === null) {
            throw PromotionsException::because('La promoción a modificar no existe.');
        }

        $promotion->changeRules(
            PromotionType::from($command->type),
            $command->value,
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
