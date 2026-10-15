{{-- Historial operativo del chofer: totales del rango + desglose día por día, con badges por motivo de
     fallo. Texto visible en inglés (regla 5). Los números vienen del QueryBus (GetDriverHistory). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        @if ($history === null)
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Choose a driver and a date range to see their operational history.') }}
                </div>
            </x-filament::section>
        @else
            {{-- Encabezado: chofer y rango mirado --}}
            <x-filament::section>
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold">{{ $history['driver_name'] }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('From') }} {{ $history['from'] }} {{ __('to') }} {{ $history['to'] }}
                    </p>
                </div>
            </x-filament::section>

            {{-- Totales del rango --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('Totals') }}</x-slot>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm md:grid-cols-4 lg:grid-cols-6">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Days worked') }}</dt>
                        <dd class="text-2xl font-semibold">{{ $history['totals']['days_worked'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Total stops') }}</dt>
                        <dd class="text-2xl font-semibold">{{ $history['totals']['total_stops'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Delivered') }}</dt>
                        <dd class="text-2xl font-semibold text-success-600 dark:text-success-400">{{ $history['totals']['delivered'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Failed') }}</dt>
                        <dd class="text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ $history['totals']['failed'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Rescheduled') }}</dt>
                        <dd class="text-2xl font-semibold text-warning-600 dark:text-warning-400">{{ $history['totals']['rescheduled'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Vehicles') }}</dt>
                        <dd class="text-2xl font-semibold">{{ count($history['totals']['vehicles']) }}</dd>
                    </div>
                </dl>

                @if (! empty($history['totals']['vehicles']))
                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('Vehicles') }}:</span>
                        @foreach ($history['totals']['vehicles'] as $plate)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono dark:bg-gray-800">{{ $plate }}</span>
                        @endforeach
                    </div>
                @endif

                @if (! empty($history['totals']['failed_by_reason']))
                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('Failed by reason') }}:</span>
                        @foreach ($history['totals']['failed_by_reason'] as $reason => $count)
                            <x-filament::badge color="danger">
                                {{ $reasonLabels[$reason] ?? $reason }}: {{ $count }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            {{-- Desglose día por día --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('Daily breakdown') }}</x-slot>
                @if (empty($history['days']))
                    <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No crews were assembled for this driver in the selected range.') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <th class="py-2 pr-4 font-medium">{{ __('Date') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ __('Vehicle') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ __('Helpers') }}</th>
                                    <th class="py-2 pr-4 font-medium text-right">{{ __('Delivered') }}</th>
                                    <th class="py-2 pr-4 font-medium text-right">{{ __('Failed') }}</th>
                                    <th class="py-2 pr-4 font-medium text-right">{{ __('Rescheduled') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history['days'] as $day)
                                    <tr class="border-b dark:border-gray-800">
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $day['date'] }}</td>
                                        <td class="py-2 pr-4 font-mono">{{ $day['vehicle_plate'] }}</td>
                                        <td class="py-2 pr-4">
                                            @if (empty($day['helper_names']))
                                                <span class="text-gray-400">—</span>
                                            @else
                                                {{ implode(', ', $day['helper_names']) }}
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right text-success-600 dark:text-success-400">{{ $day['delivered'] }}</td>
                                        <td class="py-2 pr-4 text-right text-danger-600 dark:text-danger-400">{{ $day['failed'] }}</td>
                                        <td class="py-2 pr-4 text-right">
                                            <span class="text-warning-600 dark:text-warning-400">{{ $day['rescheduled'] }}</span>
                                            @if (! empty($day['reschedule_reasons']))
                                                <div class="mt-1 flex flex-wrap justify-end gap-1">
                                                    @foreach ($day['reschedule_reasons'] as $reason)
                                                        <span class="rounded-full bg-warning-100 px-2 py-0.5 text-[10px] text-warning-700 dark:bg-warning-900 dark:text-warning-300">{{ $reason }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
