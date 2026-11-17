<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\UpdateFailureReason;

use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Edita un motivo del catálogo por su code. Aplica solo los campos presentes (los null no se tocan). El
 * code es inmutable: aquí solo cambian etiqueta, estado activo, exigencias y orden.
 *
 * @implements CommandHandler<UpdateFailureReason>
 */
final class UpdateFailureReasonHandler implements CommandHandler
{
    public function __construct(
        private readonly FailureReasonRepository $reasons,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof UpdateFailureReason) {
            throw ExecutionException::because('UpdateFailureReasonHandler solo maneja UpdateFailureReason');
        }

        $code = FailureReasonCode::fromString($command->code);
        $reason = $this->reasons->findByCode($code)
            ?? throw ExecutionException::because("no existe un motivo de entrega fallida con el código {$code->value}");

        if ($command->label !== null) {
            $reason->relabel($command->label);
        }

        if ($command->active !== null) {
            $command->active ? $reason->activate() : $reason->deactivate();
        }

        // Exigencias: se tocan si vino alguna de las tres; el valor ausente conserva el actual.
        if ($command->requiresPhoto !== null || $command->requiresNote !== null || $command->noteLabel !== null) {
            $reason->setRequirements(
                $command->requiresPhoto ?? $reason->requiresPhoto(),
                $command->requiresNote ?? $reason->requiresNote(),
                $command->noteLabel ?? $reason->noteLabel(),
            );
        }

        if ($command->sortOrder !== null) {
            $reason->reorder($command->sortOrder);
        }

        $this->reasons->save($reason);
    }
}
