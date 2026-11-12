<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationRegistry;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationSection;
use Logistics\Shared\Domain\Exception\DomainException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pantalla única «Configuration» con el formato de Magento (Stores › Configuration): menú lateral de
 * pestañas plegables con sus secciones, panel derecho con fieldsets plegables y etiquetas a la izquierda
 * con su alcance (`[global]`), y un solo botón «Save Config».
 *
 * La configuración es global (sin selector de tienda/website): el «Scope» se muestra fijo en
 * «Default Config» solo como referencia visual. Las secciones las aporta cada contexto vía
 * {@see ConfigurationRegistry}; la página no conoce claves. Cada sección se gatea por rol.
 *
 * @property-read Schema $form
 */
final class Configuration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'configuration';

    protected static ?int $navigationSort = 1;

    protected string $view = 'logistics-settings::filament.pages.configuration';

    #[Url(as: 'section')]
    public string $section = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Configuration');
    }

    public function getTitle(): string
    {
        return __('Configuration');
    }

    public static function canAccess(): bool
    {
        return app(ConfigurationRegistry::class)->accessible() !== [];
    }

    /**
     * URL directa a una sección (usada por las pantallas antiguas que redirigen aquí).
     */
    public static function sectionUrl(string $slug): string
    {
        return self::getUrl(['section' => $slug]);
    }

    public function mount(): void
    {
        $this->section = $this->current()::slug();
        $this->form->fill($this->instance()->state());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->instance()->components())
            ->inlineLabel()
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            $this->instance()->save($data);
        } catch (DomainException $e) {
            Notification::make()->title(__('The configuration was not saved.'))->body($e->getMessage())->danger()->send();

            return;
        }

        // Releer: el valor vigente puede normalizarse (secretos vacíos, defaults).
        $this->form->fill($this->instance()->state());

        Notification::make()->title(__('You saved the configuration.'))->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'navigation' => app(ConfigurationRegistry::class)->navigation(),
            'current' => $this->current(),
        ];
    }

    /**
     * @return class-string<ConfigurationSection>
     */
    private function current(): string
    {
        return app(ConfigurationRegistry::class)->resolve($this->section) ?? throw new NotFoundHttpException;
    }

    private function instance(): ConfigurationSection
    {
        return app($this->current());
    }
}
