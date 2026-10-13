{{-- Calendario de despacho: cupos por scope y franja para un día, con total/usado/disponible, más el
     resumen de los horarios configurados. Texto visible en inglés (regla 5). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Navegación de día + resumen de horarios configurados --}}
        <x-filament::section>
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div class="flex items-end gap-3">
                    <x-filament::button color="gray" wire:click="shiftDay(-1)" icon="heroicon-m-chevron-left">
                        {{ __('Previous day') }}
                    </x-filament::button>

                    <div>
                        <label for="dispatch-calendar-date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('Delivery date') }}
                        </label>
                        <input
                            id="dispatch-calendar-date"
                            type="date"
                            wire:model.live="date"
                            class="mt-1 rounded-lg border-gray-300 shadow-sm dark:bg-gray-900 dark:border-gray-700"
                        />
                    </div>

                    <x-filament::button color="gray" wire:click="shiftDay(1)" icon="heroicon-m-chevron-right" icon-position="after">
                        {{ __('Next day') }}
                    </x-filament::button>
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
            </div>

            @if (! empty($windows))
                <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Configured windows') }}:</span>
                    @foreach ($windows as $window)
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono dark:bg-gray-800">{{ $window }}</span>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Historial de versiones de las reglas de cupo (settings): inmutable, de la más nueva a la
             más vieja. Editar reglas publica una versión nueva; esto deja ver la traza de cambios. --}}
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

        {{-- Cupos del día, agrupados por scope --}}
        @forelse ($scopes as $scope)
            <x-filament::section>
                <x-slot name="heading">
                    <span class="inline-flex items-center gap-2">
                        <span class="rounded bg-primary-50 px-2 py-0.5 text-xs font-semibold uppercase text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                            {{ $scope['kind'] }}
                        </span>
                        {{ $scope['label'] }}
                    </span>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4 font-medium">{{ __('Time window') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Unit') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Total') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Used') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Available') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($scope['rows'] as $row)
                                <tr class="border-b last:border-0 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-mono">{{ $row['time_slot'] }}</td>
                                    <td class="py-2 pr-4">{{ $unitLabels[$row['unit']] ?? $row['unit'] }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['total'], 3) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['used'], 3) }}</td>
                                    <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ number_format($row['available'], 3) }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($row['full'])
                                            <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                                                {{ __('Full') }}
                                            </span>
                                        @else
                                            <span class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-950 dark:text-success-300">
                                                {{ __('Open') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="py-8 text-center text-gray-500 dark:text-gray-400">
                    {{ __('No capacity buckets defined for :date.', ['date' => $date]) }}
                </div>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
