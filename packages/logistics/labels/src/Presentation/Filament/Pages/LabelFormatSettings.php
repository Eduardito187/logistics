<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Pages;

use App\Models\User;
use BackedEnum;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Infrastructure\Settings\SettingsLabelSymbologyProvider;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Ajuste del FORMATO (simbología) de las etiquetas: elegir si se imprime el QR firmado, el código de
 * barras Zebra (Code128) o ambos. Escribe el setting versionado `labels.symbology` por el CommandBus; la
 * emisión y la vista previa leen ese valor. El código único de la etiqueta (`label_id`) va SIEMPRE, sea
 * cual sea la simbología, para que el lector pueda resolver el bulto con cualquiera.
 *
 * Solo oficina (admin/supervisor). Texto visible en inglés (regla 5); comentarios en español (regla 6).
 *
 * @property-read Schema $form
 */
final class LabelFormatSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 70;

    protected string $view = 'filament.labels.label-format-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): string
    {
        // El grupo «Configuración» del admin usa la clave 'Administration' (ver es.json y AdminPanelProvider).
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Label format');
    }

    public function getTitle(): string
    {
        return __('Label format');
    }

    public function mount(): void
    {
        $current = app(LabelSymbologyProvider::class)->current(new DateTimeImmutable);
        $this->form->fill(['symbology' => $current->value]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Printed symbology'))
                    ->description(__('Choose what the label prints. The unique label code is always shown below so a scanner can read either one.'))
                    ->schema([
                        Radio::make('symbology')
                            ->hiddenLabel()
                            ->options([
                                LabelSymbology::Qr->value => __('QR only (signed, scanned by the app offline)'),
                                LabelSymbology::Barcode->value => __('Zebra barcode only (Code128, read by a 1D laser scanner)'),
                                LabelSymbology::Both->value => __('Both (QR + Zebra)'),
                            ])
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var array{symbology?: string} $state */
        $state = $this->form->getState();
        $value = LabelSymbology::fromValue($state['symbology'] ?? null);

        $user = auth()->user();

        app(CommandBus::class)->dispatch(new SetSettingCommand(
            key: SettingsLabelSymbologyProvider::SETTING_KEY,
            value: [SettingsLabelSymbologyProvider::SETTING_FIELD => $value->value],
            effectiveFrom: (new DateTimeImmutable)->format(DATE_ATOM),
            createdBy: $user instanceof User ? (string) $user->email : 'admin',
        ));

        Notification::make()
            ->title(__('Label format saved'))
            ->body(__('New labels and previews will use the selected symbology. Reprint existing labels to apply it to them.'))
            ->success()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save'))
                ->submit('save'),
        ];
    }
}
