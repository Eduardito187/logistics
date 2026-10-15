<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Notifications\Application\Command\UpsertMessageTemplate\UpsertMessageTemplate;
use Logistics\Notifications\Presentation\Http\Request\UpsertMessageTemplateRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Crea o actualiza una plantilla de mensaje (puerta app). Responde 200 con la clave de la plantilla.
 */
final readonly class UpsertMessageTemplateController
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(UpsertMessageTemplateRequest $request): JsonResponse
    {
        /** @var array{message_type: string, channel: string, locale?: string|null, subject?: string|null, body: string} $data */
        $data = $request->validated();

        $locale = $data['locale'] ?? 'es';

        $this->commandBus->dispatch(new UpsertMessageTemplate(
            messageType: $data['message_type'],
            channel: $data['channel'],
            locale: $locale,
            subject: $data['subject'] ?? null,
            body: $data['body'],
        ));

        return new JsonResponse([
            'data' => [
                'message_type' => $data['message_type'],
                'channel' => $data['channel'],
                'locale' => $locale,
                'status' => 'saved',
            ],
        ]);
    }
}
