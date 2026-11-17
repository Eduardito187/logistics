<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CreateFailureReason;

use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Da de alta un motivo de entrega fallida. Si no se pasa code, se genera de la etiqueta (slug en
 * MAYÚSCULAS). Rechaza un code repetido (regla de negocio → 409 en la puerta).
 *
 * @implements CommandHandler<CreateFailureReason>
 */
final class CreateFailureReasonHandler implements CommandHandler
{
    public function __construct(
        private readonly FailureReasonRepository $reasons,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof CreateFailureReason) {
            throw ExecutionException::because('CreateFailureReasonHandler solo maneja CreateFailureReason');
        }

        // Code explícito o derivado de la etiqueta; el VO lo normaliza (MAYÚSCULAS + guion bajo).
        $code = $command->code !== null && trim($command->code) !== ''
            ? FailureReasonCode::fromString($command->code)
            : FailureReasonCode::fromLabel($command->label);

        if ($this->reasons->existsByCode($code)) {
            throw ExecutionException::because("ya existe un motivo de entrega fallida con el código {$code->value}");
        }

        $reason = FailureReason::create(
            $this->reasons->nextIdentity(),
            $code,
            $command->label,
            $command->active,
            $command->requiresPhoto,
            $command->requiresNote,
            $command->noteLabel,
            $command->sortOrder,
        );

        $this->reasons->save($reason);
    }
}
