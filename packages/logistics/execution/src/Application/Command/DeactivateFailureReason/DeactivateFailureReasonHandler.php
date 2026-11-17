<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\DeactivateFailureReason;

use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Desactiva un motivo del catálogo (soft delete). Idempotente: desactivar uno ya inactivo no cambia nada.
 *
 * @implements CommandHandler<DeactivateFailureReason>
 */
final class DeactivateFailureReasonHandler implements CommandHandler
{
    public function __construct(
        private readonly FailureReasonRepository $reasons,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof DeactivateFailureReason) {
            throw ExecutionException::because('DeactivateFailureReasonHandler solo maneja DeactivateFailureReason');
        }

        $code = FailureReasonCode::fromString($command->code);
        $reason = $this->reasons->findByCode($code)
            ?? throw ExecutionException::because("no existe un motivo de entrega fallida con el código {$code->value}");

        $reason->deactivate();
        $this->reasons->save($reason);
    }
}
