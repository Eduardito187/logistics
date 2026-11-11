<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Presentation\Filament\Pages\LabelFormatSettings;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;
use Logistics\Settings\Presentation\Filament\Pages\Configuration;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Pantalla «Configuración» estilo Magento: menú de pestañas → secciones gateado por rol, etiquetas con
 * alcance [global], «Save Config» que publica por el bus CQRS y lo que leen las políticas de cada contexto.
 */
const CFG_SKIP = 'Requiere PostgreSQL (base logistics_test).';

beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped(CFG_SKIP);
    }
    Filament::setCurrentPanel('admin');
});

function configurationUser(Role $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

function configurationSetting(string $key): mixed
{
    return app(QueryBus::class)->ask(new GetSettingQuery($key));
}

it('el admin ve todas las pestañas, con el alcance global bajo cada etiqueta', function (): void {
    configurationUser(Role::Admin);

    Livewire::withQueryParams(['section' => 'integrations-routing'])
        ->test(Configuration::class)
        ->assertOk()
        ->assertSee(array_map(__(...), ['Save Config', 'Default Config', 'Fleet', 'Labels', 'Integrations', 'SAP connector', 'Crew shifts']))
        ->assertSeeHtml('<span class="cfg-scope">['.e(__('global')).']</span>')
        ->assertSeeHtml('aria-current="page"');
});

it('el supervisor no ve Integraciones: pedir esa sección cae en la primera permitida', function (): void {
    configurationUser(Role::Supervisor);

    Livewire::withQueryParams(['section' => 'integrations-sap'])
        ->test(Configuration::class)
        ->assertOk()
        ->assertSet('section', 'fleet-documents')
        ->assertDontSee('SAP connector');
});

it('un rol de campo no accede a la configuración', function (): void {
    configurationUser(Role::Chofer);

    expect(Configuration::canAccess())->toBeFalse();
});

it('guardar ruteo publica la clave de integración', function (): void {
    configurationUser(Role::Admin);

    Livewire::withQueryParams(['section' => 'integrations-routing'])
        ->test(Configuration::class)
        ->set('data.enabled', true)
        ->set('data.backend', 'vroom')
        ->set('data.osrm_host', 'https://osrm.example.com')
        ->set('data.osrm_timeout', 7)
        ->set('data.vroom_url', 'https://vroom.example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified();

    expect(configurationSetting(IntegrationKeys::ROUTING))->toMatchArray([
        'enabled' => true,
        'backend' => 'vroom',
        'osrm_host' => 'https://osrm.example.com',
        'osrm_timeout' => 7,
        'vroom_url' => 'https://vroom.example.com',
    ]);
});

it('la contraseña SAP se guarda cifrada y en blanco conserva la anterior', function (): void {
    configurationUser(Role::Admin);

    $page = Livewire::withQueryParams(['section' => 'integrations-sap'])
        ->test(Configuration::class)
        ->set('data.base_url', 'https://sap.example.com/odata')
        ->set('data.password', 's3cret-pass')
        ->call('save')
        ->assertHasNoErrors();

    $stored = configurationSetting(IntegrationKeys::SAP);
    expect($stored['password'])->not->toBe('s3cret-pass')
        ->and(Crypt::decryptString($stored['password']))->toBe('s3cret-pass');

    // El secreto nunca vuelve en claro al formulario; guardar en blanco conserva el cifrado anterior.
    $page->assertSet('data.password', '')
        ->set('data.username', 'svc-logistics')
        ->call('save')
        ->assertHasNoErrors();

    $again = configurationSetting(IntegrationKeys::SAP);
    expect($again['username'])->toBe('svc-logistics')
        ->and(Crypt::decryptString($again['password']))->toBe('s3cret-pass');
});

it('la ventana de alerta de documentos guardada es la que usa la política de flota', function (): void {
    configurationUser(Role::Supervisor);

    Livewire::withQueryParams(['section' => 'fleet-documents'])
        ->test(Configuration::class)
        ->set('data.days', 12)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(DocumentExpiryPolicy::class)->alertWindowDays())->toBe(12);
});

it('las reglas de turno se editan como hora del día y se guardan en minutos', function (): void {
    configurationUser(Role::Admin);

    Livewire::withQueryParams(['section' => 'fleet-shifts'])
        ->test(Configuration::class)
        ->set('data.earliest_start', '06:30')
        ->set('data.latest_end', '21:00')
        ->set('data.max_daily_minutes', 540)
        ->set('data.min_rest_minutes', 600)
        ->call('save')
        ->assertHasNoErrors();

    $rules = app(ShiftRulesPolicy::class)->rules();
    expect([$rules->earliestStartMinute, $rules->latestEndMinute, $rules->maxDailyMinutes, $rules->minRestMinutes])
        ->toBe([390, 1260, 540, 600]);
});

it('un turno que termina antes de empezar no se guarda', function (): void {
    configurationUser(Role::Admin);

    Livewire::withQueryParams(['section' => 'fleet-shifts'])
        ->test(Configuration::class)
        ->set('data.earliest_start', '20:00')
        ->set('data.latest_end', '08:00')
        ->call('save')
        ->assertHasErrors(['data.latest_end']);

    expect(configurationSetting('fleet.crew_shift_rules'))->toBeNull();
});

it('el formato de etiqueta guardado es el que usa la emisión', function (): void {
    configurationUser(Role::Admin);

    Livewire::withQueryParams(['section' => 'labels-format'])
        ->test(Configuration::class)
        ->set('data.symbology', LabelSymbology::Qr->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(LabelSymbologyProvider::class)->current(new DateTimeImmutable))->toBe(LabelSymbology::Qr);
});

it('la ruta vieja del formato de etiqueta redirige a su sección', function (): void {
    configurationUser(Role::Admin);

    Livewire::test(LabelFormatSettings::class)
        ->assertRedirect(Configuration::sectionUrl('labels-format'));
});
