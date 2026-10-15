<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\CreateMessageTemplate;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\EditMessageTemplate;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\ListMessageTemplates;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\CreateMessageTypeRule;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\EditMessageTypeRule;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\ListMessageTypeRules;
use Logistics\Notifications\Presentation\Filament\Resources\NotificationResource\Pages\ListNotifications;
use Logistics\Notifications\Presentation\Filament\Resources\NotificationResource\Pages\ViewNotification;

/**
 * Cobertura de render de los recursos y páginas Filament del contexto de notificaciones (patrón A).
 * Montar las páginas Livewire ejecuta table()/form()/infolist()/getTabs() y las columnas calculadas
 * (destinatario, intentos, cascada), todo en lectura contra la base real (logistics_test).
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('renderiza el log de notificaciones con registros con y sin contacto', function (): void {
    NotificationRecord::factory()->create(['email' => 'cliente@dismac.test', 'phone' => null]);
    NotificationRecord::factory()->create(['email' => null, 'phone' => '+59171234567']);
    NotificationRecord::factory()->sent()->create(['email' => null, 'phone' => null]);

    Livewire::test(ListNotifications::class)->assertOk();
});

it('renderiza el detalle de una notificación encolada', function (): void {
    $record = NotificationRecord::factory()->create([
        'email' => 'cliente@dismac.test',
        'attempts' => [Channel::PushNtfy->value => 2],
    ]);

    Livewire::test(ViewNotification::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza el listado de plantillas de mensaje', function (): void {
    // La clave única es (message_type, channel, locale): se varía el tipo para no colisionar entre las dos.
    MessageTemplateRecord::factory()
        ->count(2)
        ->sequence(
            ['message_type' => MessageType::EnRoute->value],
            ['message_type' => MessageType::Delivered->value],
        )
        ->create([
            'channel' => Channel::Email->value,
        ]);

    Livewire::test(ListMessageTemplates::class)->assertOk();
});

it('renderiza el create de plantillas de mensaje', function (): void {
    Livewire::test(CreateMessageTemplate::class)->assertOk();
});

it('renderiza el edit de una plantilla de mensaje', function (): void {
    $record = MessageTemplateRecord::factory()->create();

    Livewire::test(EditMessageTemplate::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza el listado de reglas de cascada', function (): void {
    MessageTypeRuleRecord::factory()->create();

    Livewire::test(ListMessageTypeRules::class)->assertOk();
});

it('renderiza el create de reglas de cascada', function (): void {
    Livewire::test(CreateMessageTypeRule::class)->assertOk();
});

it('renderiza el edit de una regla de cascada', function (): void {
    $record = MessageTypeRuleRecord::factory()->create();

    Livewire::test(EditMessageTypeRule::class, ['record' => $record->getKey()])->assertOk();
});
