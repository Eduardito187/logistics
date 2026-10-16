<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Logistics\Inbound\Application\Command\LabelReceivedUnits\LabelReceivedUnits;
use Logistics\Inbound\Application\Command\RecordArrival\RecordArrival;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsn;
use Logistics\Inbound\Application\Query\ListAsns\ListAsns;
use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Domain\ValueObject\AsnStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Cockpit "Inbound board" (grupo Operations, patrón operaciones-primero como LastMileBoard).
 *
 * NO es un CRUD: es la sala de recepción. Muestra los ASNs del día segmentados por estado operativo
 * —anunciados (por agendar), agendados (por llegar), en recepción— con sus acciones inline que despachan
 * los Commands de inbound por el CommandBus (Presentation → Application, nunca Infrastructure/Domain).
 *
 * Lectura: la cola se arma desde el lado de consulta CQRS (QueryBus → ListAsns) y enriquece el nombre del
 * seller read-only. Todo texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class InboundBoard extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 6;

    protected string $view = 'logistics-inbound::filament.pages.inbound-board';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Inbound board');
    }

    public function getTitle(): string
    {
        return __('Inbound board');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'segments' => $this->segments(),
            'generatedAt' => now(),
        ];
    }

    /**
     * Segmentos del tablero en orden operativo (lo que requiere acción primero).
     *
     * @return list<array{key: string, label: string, color: string, count: int, rows: list<array<string, mixed>>}>
     */
    private function segments(): array
    {
        /** @var QueryBus $queries */
        $queries = app(QueryBus::class);

        /** @var array<string, array{label: string, color: string, status: string}> $definitions */
        $definitions = [
            'announced' => ['label' => __('Announced'), 'color' => 'gray', 'status' => AsnStatus::Announced->value],
            'scheduled' => ['label' => __('Scheduled'), 'color' => 'warning', 'status' => AsnStatus::Scheduled->value],
            'arrived' => ['label' => __('Arrived'), 'color' => 'info', 'status' => AsnStatus::Arrived->value],
            'receiving' => ['label' => __('Receiving'), 'color' => 'info', 'status' => AsnStatus::Receiving->value],
            'received' => ['label' => __('Received'), 'color' => 'success', 'status' => AsnStatus::Received->value],
        ];

        $segments = [];
        foreach ($definitions as $key => $definition) {
            /** @var list<AsnView> $asns */
            $asns = $queries->ask(new ListAsns(status: $definition['status']));
            $rows = array_map(fn (AsnView $asn): array => $this->row($asn), $asns);

            $segments[] = [
                'key' => $key,
                'label' => $definition['label'],
                'color' => $definition['color'],
                'count' => count($rows),
                'rows' => $rows,
            ];
        }

        return $segments;
    }

    /**
     * Mapea un ASN a una fila legible: seller (nombre), almacén, cita y nº de líneas. Nunca expone UUIDs.
     *
     * @return array<string, mixed>
     */
    private function row(AsnView $asn): array
    {
        $appointment = null;
        if ($asn->appointment !== null) {
            $appointment = $asn->appointment['date'].' '.$asn->appointment['time_slot'];
        }

        return [
            'id' => $asn->id,
            'seller' => $this->sellerName($asn->sellerId),
            'warehouse' => substr($asn->warehouseId, 0, 8),
            'appointment' => $appointment,
            'status' => $asn->status,
            'lines' => count($asn->lines),
        ];
    }

    /**
     * Acción inline "Schedule": despacha ScheduleAsn para el ASN dado (reserva cupo de andén).
     */
    public function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label(__('Schedule'))
            ->icon('heroicon-o-calendar-days')
            ->modalHeading(__('Schedule appointment'))
            ->modalSubmitActionLabel(__('Schedule'))
            ->schema([
                TextInput::make('appointment_date')->label(__('Date'))->type('date')->required(),
                TextInput::make('start_time')->label(__('Start time'))->type('time')->required(),
                TextInput::make('end_time')->label(__('End time'))->type('time')->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->dispatchCommand(new ScheduleAsn(
                    asnId: $this->argAsn($arguments),
                    appointmentDate: (string) $data['appointment_date'],
                    startTime: substr((string) $data['start_time'], 0, 5),
                    endTime: substr((string) $data['end_time'], 0, 5),
                ), __('Appointment scheduled'));
            });
    }

    /**
     * Acción inline "Record arrival": despacha RecordArrival para el ASN dado.
     */
    public function arrivalAction(): Action
    {
        return Action::make('arrival')
            ->label(__('Record arrival'))
            ->icon('heroicon-o-truck')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Record arrival'))
            ->action(function (array $arguments): void {
                $this->dispatchCommand(new RecordArrival(
                    asnId: $this->argAsn($arguments),
                ), __('Arrival recorded'));
            });
    }

    /**
     * Acción inline "Label units": despacha LabelReceivedUnits para el ASN dado (punto de convergencia).
     */
    public function labelAction(): Action
    {
        return Action::make('label')
            ->label(__('Label units'))
            ->icon('heroicon-o-qr-code')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Label received units'))
            ->action(function (array $arguments): void {
                $this->dispatchCommand(new LabelReceivedUnits(
                    asnId: $this->argAsn($arguments),
                ), __('Units labeled'));
            });
    }

    /**
     * Despacha un comando por el CommandBus y notifica. No se llama `dispatch` (colisiona con Livewire).
     */
    private function dispatchCommand(Command $command, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);

            Notification::make()->title($successTitle)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Could not complete the action'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Id del ASN que llega como argumento de la acción inline, como string.
     *
     * @param array<string, mixed> $arguments
     */
    private function argAsn(array $arguments): string
    {
        return isset($arguments['asn']) && is_string($arguments['asn']) ? $arguments['asn'] : '';
    }

    /**
     * Nombre legal del seller (lectura read-only de la tabla sellers, sin dep composer). Cae al id corto.
     */
    private function sellerName(string $sellerId): string
    {
        $name = DB::table('sellers')->where('id', $sellerId)->value('legal_name');

        return is_string($name) && $name !== '' ? $name : substr($sellerId, 0, 8);
    }
}
