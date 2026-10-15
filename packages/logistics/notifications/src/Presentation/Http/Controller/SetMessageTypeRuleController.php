<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRule;
use Logistics\Notifications\Presentation\Http\Request\SetMessageTypeRuleRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Configura (upsert) la cascada de un tipo de mensaje (puerta app). Responde 200 con el tipo afectado.
 */
final readonly class SetMessageTypeRuleController
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(SetMessageTypeRuleRequest $request): JsonResponse
    {
        /** @var array{message_type: string, channels: list<string>, escalate_when: string} $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new SetMessageTypeRule(
            messageType: $data['message_type'],
            channels: array_values($data['channels']),
            escalateWhen: $data['escalate_when'],
        ));

        return new JsonResponse([
            'data' => [
                'message_type' => $data['message_type'],
                'channels' => array_values($data['channels']),
                'escalate_when' => $data['escalate_when'],
            ],
        ]);
    }
}
