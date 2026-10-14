{{-- Tablero de costos de flota: mantenimientos por vencer (overdue/due-soon) y resumen de costos de un
     vehículo en un rango. Texto visible en inglés (regla 5); comentarios en español (regla 6). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Resumen de costos del vehículo elegido (si ya se calculó uno desde la acción de cabecera) --}}
        @if ($summary !== null)
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('Cost summary') }} — {{ $summary['vehicle'] }}
                </x-slot>
                <x-slot name="description">
                    {{ $summary['from'] }} → {{ $summary['to'] }}
                </x-slot>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-3 xl:grid-cols-6">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Fuel') }}</dt>
                        <dd class="text-lg font-semibold">{{ $summary['fuel'] }} {{ $summary['currency'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Maintenance') }}</dt>
                        <dd class="text-lg font-semibold">{{ $summary['maintenance'] }} {{ $summary['currency'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Expenses') }}</dt>
                        <dd class="text-lg font-semibold">{{ $summary['expenses'] }} {{ $summary['currency'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Total cost') }}</dt>
                        <dd class="text-lg font-semibold">{{ $summary['total'] }} {{ $summary['currency'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Kilometers') }}</dt>
                        <dd class="text-lg font-semibold">{{ $summary['kilometers'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Cost per km') }}</dt>
                        <dd class="text-lg font-semibold">{{ $summary['cost_per_km'] }} {{ $summary['currency'] }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif

        {{-- Mantenimientos preventivos por vencer o vencidos --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Due maintenance') }}
            </x-slot>

            @if (count($due) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No maintenance due.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('Vehicle') }}</th>
                                <th class="py-2 pr-4">{{ __('Service kind') }}</th>
                                <th class="py-2 pr-4">{{ __('Next due (km)') }}</th>
                                <th class="py-2 pr-4">{{ __('Next due at') }}</th>
                                <th class="py-2 pr-4">{{ __('Odometer') }}</th>
                                <th class="py-2 pr-4">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($due as $row)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="py-2 pr-4 font-medium">{{ $row['vehicle'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['service_kind'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['next_due_km'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['next_due_at'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['current_odometer_km'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($row['overdue'])
                                            <x-filament::badge color="danger">{{ __('Overdue') }}</x-filament::badge>
                                        @else
                                            <x-filament::badge color="warning">{{ __('Due soon') }}</x-filament::badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
