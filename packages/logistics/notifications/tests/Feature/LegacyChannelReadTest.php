<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentMessageTypeRuleRepository;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Regresión del 500 de `GET /app/notifications/all`: filas persistidas con canales históricos del enum
 * (`PUSH_FCM`, `PUSH_NTFY`, `WEB_PUSH`, `WHATSAPP`) no deben tumbar los listados. Los alias del push se
 * leen como `FCM`; un canal actual sin equivalente hace que la fila se omita (con log), no un 500.
 * Cubre además la migración de datos que normaliza esas filas (idempotente).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param array<string, mixed> $overrides
 */
function legacyNotificationRow(array $overrides = []): NotificationRecord
{
    return NotificationRecord::factory()->create(array_merge([
        'shipment_id' => Uuid::generate()->value,
        'current_channel' => 'PUSH_FCM',
        'policy' => ['channels' => ['PUSH_FCM', 'WEB_PUSH', 'EMAIL', 'SMS'], 'escalate_when' => 'NO_OPEN'],
        'attempts' => ['PUSH_FCM' => 1],
    ], $overrides));
}

it('Channel::fromStored traduce alias históricos y devuelve null para canales retirados', function (): void {
    expect(Channel::fromStored('PUSH_FCM'))->toBe(Channel::Fcm)
        ->and(Channel::fromStored('PUSH_NTFY'))->toBe(Channel::Fcm)
        ->and(Channel::fromStored('FCM'))->toBe(Channel::Fcm)
        ->and(Channel::fromStored('email'))->toBe(Channel::Email)
        ->and(Channel::fromStored('WEB_PUSH'))->toBeNull()
        ->and(Channel::fromStored('WHATSAPP'))->toBeNull();
});

it('GET /app/notifications/all responde 200 con filas PUSH_FCM y omite las irreconstruibles', function (): void {
    $legacy = legacyNotificationRow();
    $retired = legacyNotificationRow(['current_channel' => 'WEB_PUSH']);
    $current = NotificationRecord::factory()->create();

    $response = $this->getJson('/api/v1/app/notifications/all?limit=30')->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($legacy->id)
        ->and($ids)->toContain($current->id)
        ->and($ids)->not->toContain($retired->id);

    $legacyView = collect($response->json('data'))->firstWhere('id', $legacy->id);
    expect($legacyView['current_channel'] ?? null)->toBe('FCM');
});

it('el repositorio reconstituye la fila vieja con canales y intentos normalizados', function (): void {
    $legacy = legacyNotificationRow(['attempts' => ['PUSH_FCM' => 1, 'PUSH_NTFY' => 2, 'WHATSAPP' => 1]]);

    $notification = collect(app(NotificationRepository::class)->findByShipment($legacy->shipment_id))->first();

    expect($notification)->not->toBeNull()
        ->and($notification->currentChannel())->toBe(Channel::Fcm)
        ->and($notification->policy()->channels)->toBe([Channel::Fcm, Channel::Email, Channel::Sms])
        ->and($notification->attempts())->toBe(['FCM' => 3]);
});

it('las reglas y plantillas con canales históricos se leen sin reventar', function (): void {
    MessageTypeRuleRecord::query()->create([
        'message_type' => MessageType::ConfirmLocation->value,
        'channels' => ['PUSH_FCM', 'WEB_PUSH', 'EMAIL', 'SMS'],
        'escalate_when' => 'NO_OPEN',
    ]);
    MessageTemplateRecord::query()->create([
        'id' => Uuid::generate()->value,
        'message_type' => MessageType::EnRoute->value,
        'channel' => 'WHATSAPP',
        'locale' => 'es',
        'subject' => '',
        'body' => 'viejo',
    ]);

    $policy = (new EloquentMessageTypeRuleRepository)->policyFor(MessageType::ConfirmLocation);

    expect($policy?->channels)->toBe([Channel::Fcm, Channel::Email, Channel::Sms])
        ->and(app(MessageTemplateRepository::class)->all())->toBeArray();
});

it('la migración de datos normaliza las filas viejas y es idempotente', function (): void {
    $legacy = legacyNotificationRow();
    MessageTemplateRecord::query()->create([
        'id' => Uuid::generate()->value,
        'message_type' => MessageType::Delivered->value,
        'channel' => 'PUSH_FCM',
        'locale' => 'es',
        'subject' => '',
        'body' => 'entregado',
    ]);

    // getRequire() evalúa el archivo y devuelve la instancia (migración anónima) sin usar el
    // constructo require/require_once: funciona aunque el migrador ya haya cargado el archivo.
    $migration = File::getRequire(__DIR__.'/../../database/migrations/2026_10_19_000100_normalize_legacy_notification_channels.php');
    $migration->up();
    $migration->up();

    $row = NotificationRecord::query()->findOrFail($legacy->id);
    expect($row->current_channel)->toBe('FCM')
        ->and($row->attempts)->toBe(['FCM' => 1])
        ->and($row->policy['channels'])->toBe(['FCM', 'WEB_PUSH', 'EMAIL', 'SMS'])
        ->and(MessageTemplateRecord::query()
            ->where('message_type', MessageType::Delivered->value)
            ->pluck('channel')->all())->toBe(['FCM']);
});
