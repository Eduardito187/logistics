<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\AcknowledgeAlert;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\AlertId;

/**
 * Reconoce una alerta y publica sus eventos al outbox. El instante de reconocimiento lo pone el Clock.
 *
 * @implements CommandHandler<AcknowledgeAlert>
 */
final readonly class AcknowledgeAlertHandler implements CommandHandler
{
    public function __construct(
        private AlertRepository $alerts,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AcknowledgeAlert);

        $alert = $this->alerts->findById(AlertId::fromString($command->alertId));
        if ($alert === null) {
            throw TelemetryException::because("No existe la alerta {$command->alertId}.");
        }

        $alert->acknowledge($command->by, $this->clock->now());

        $this->alerts->save($alert);

        $this->recordEvents->record($alert->releaseEvents(), 'telemetry.alert');
    }
}
