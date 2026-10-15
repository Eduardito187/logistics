{{-- Calendario de despacho por municipio: vista de los 7 días de la semana con total/usado/disponible
     por ventana, días bloqueados marcados, horizonte activo y navegación por semana. Mutaciones (activar
     cupos, bloquear/desbloquear) van por los Commands del CommandBus. Texto visible en inglés (regla 5). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filtro de municipio (select buscable, native(false)) + navegación de semana + horizonte activo --}}
        <x-filament::section>
            <div class="space-y-4">
                {{ $this->getFiltersForm() }}

                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-center gap-2">
                        <x-filament::button color="gray" wire:click="shiftWeek(-1)" icon="heroicon-m-chevron-left">
                            {{ __('Previous week') }}
                        </x-filament::button>
                        <x-filament::button color="gray" wire:click="goToThisWeek">
                            {{ __('This week') }}
                        </x-filament::button>
                        <x-filament::button color="gray" wire:click="shiftWeek(1)" icon="heroicon-m-chevron-right" icon-position="after">
                            {{ __('Next week') }}
                        </x-filament::button>
                        <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ $weekStart }} — {{ $weekEnd }}
                        </span>
                    </div>

                    <div class="text-sm">
                        @if ($activeHorizon)
                            <span class="text-gray-500 dark:text-gray-400">{{ __('Slots active until') }}:</span>
                            <span class="font-semibold text-success-600 dark:text-success-400">{{ $activeHorizon['active_until'] }}</span>
                            @if ($activeHorizon['global'])
                                <span class="ml-1 text-xs text-gray-400">({{ __('Global') }})</span>
                            @endif
                        @else
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ __('No active horizon yet. Use "Activate slots" to open a date range.') }}
                            </span>
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm md:grid-cols-4">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Business days') }}</dt>
                        <dd class="font-medium">{{ $schedule['business_days'] ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Order cutoff') }}</dt>
                        <dd class="font-medium">{{ $schedule['order_cutoff'] ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Horizon (days)') }}</dt>
                        <dd class="font-medium">{{ $schedule['horizon_days'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Scopes') }}</dt>
                        <dd class="font-medium">{{ $schedule['scopes_count'] }}</dd>
                    </div>
                </dl>

                @if ($municipalityLabel)
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('Showing weekly slots for') }}: <span class="font-semibold">{{ $municipalityLabel }}</span>
                    </p>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Showing slots across all municipalities. Pick one above to focus the week.') }}
                    </p>
                @endif
            </div>
        </x-filament::section>

        {{-- Vista semanal: siete tarjetas (Lun–Dom). Cada día marca si está bloqueado (feriado) o fuera
             del horizonte activo, y lista sus ventanas con total/usado/disponible. --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
            @foreach ($week as $day)
                <div @class([
                    'flex flex-col rounded-xl border p-3 shadow-sm',
                    'border-danger-300 bg-danger-50 dark:border-danger-800 dark:bg-danger-950/40' => $day['blocked'],
                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => ! $day['blocked'],
                ])>
                    <div class="mb-2 flex items-center justify-between">
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $day['weekday_label'] }}</div>
                            <div class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d/m') }}</div>
                        </div>
                        @if ($day['is_today'])
                            <span class="rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                {{ __('Today') }}
                            </span>
                        @endif
                    </div>

                    @if ($day['blocked'])
                        <div class="mb-2 rounded-lg bg-danger-100 px-2 py-1 text-xs text-danger-800 dark:bg-danger-900/50 dark:text-danger-200">
                            <div class="font-semibold">{{ __('Blocked') }}</div>
                            @if ($day['reason'])
                                <div class="truncate" title="{{ $day['reason'] }}">{{ $day['reason'] }}</div>
                            @endif
                        </div>
                    @elseif (! $day['within_horizon'])
                        <div class="mb-2 rounded-lg bg-warning-100 px-2 py-1 text-[11px] text-warning-800 dark:bg-warning-900/50 dark:text-warning-200">
                            {{ __('Outside active horizon') }}
                        </div>
                    @endif

                    <div class="flex-1 space-y-2">
                        @forelse ($day['windows'] as $window)
                            <div class="rounded-lg border border-gray-100 p-2 text-xs dark:border-gray-800">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono font-medium text-gray-800 dark:text-gray-100">{{ $window['time_slot'] }}</span>
                                    @if ($window['full'])
                                        <span class="rounded-full bg-danger-100 px-1.5 py-0.5 text-[10px] font-medium text-danger-700 dark:bg-danger-950 dark:text-danger-300">{{ __('Full') }}</span>
                                    @else
                                        <span class="rounded-full bg-success-100 px-1.5 py-0.5 text-[10px] font-medium text-success-700 dark:bg-success-950 dark:text-success-300">{{ __('Open') }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-gray-500 dark:text-gray-400">{{ $unitLabels[$window['unit']] ?? $window['unit'] }}</div>
                                <div class="mt-1 flex items-center justify-between tabular-nums">
                                    <span class="text-gray-500 dark:text-gray-400">{{ __('Avail.') }}</span>
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">
                                        {{ number_format($window['available'], 2) }} / {{ number_format($window['total'], 2) }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-200 py-3 text-center text-[11px] text-gray-400 dark:border-gray-700">
                                {{ __('No slots') }}
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-3 border-t border-gray-100 pt-2 dark:border-gray-800">
                        @if ($day['blocked'])
                            <x-filament::button size="xs" color="success" class="w-full" wire:click="unblockDay('{{ $day['date'] }}')" wire:confirm="{{ __('Unblock this dispatch day?') }}">
                                {{ __('Unblock') }}
                            </x-filament::button>
                        @else
                            <x-filament::button size="xs" color="gray" class="w-full" wire:click="mountAction('block_day', { date: '{{ $day['date'] }}' })">
                                {{ __('Block') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if (! empty($windows))
            <x-filament::section>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Configured windows') }}:</span>
                    @foreach ($windows as $window)
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono dark:bg-gray-800">{{ $window }}</span>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- Historial de versiones de las reglas de cupo (settings): inmutable, de la más nueva a la más
             vieja. Editar reglas publica una versión nueva; esto deja ver la traza de cambios. --}}
        @if (! empty($history))
            <x-filament::section>
                <x-slot name="heading">{{ __('Capacity rules history') }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4 font-medium">{{ __('Version') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Effective from') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Created at') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Created by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $version)
                                <tr class="border-b last:border-0 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-mono">{{ $version['version'] }}</td>
                                    <td class="py-2 pr-4">{{ $version['effective_from'] }}</td>
                                    <td class="py-2 pr-4">{{ $version['created_at'] }}</td>
                                    <td class="py-2 pr-4">{{ $version['created_by'] ?: __('Unknown') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
