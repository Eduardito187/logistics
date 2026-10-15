{{-- Cockpit de salud de flota (operaciones-primero): arriba las excepciones que requieren acción (con
     acción inline o enlace de drill-down), y los KPIs de costo al final. Texto visible en inglés (regla 5);
     comentarios en español (regla 6). --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 1) Mantenimientos vencidos / por vencer — acción inline: agendar orden (ScheduleMaintenance) --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Overdue & upcoming maintenance') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Preventive maintenance that is overdue or coming due.') }}
            </x-slot>
            @if ($ordersUrl !== null)
                <x-slot name="headerEnd">
                    <x-filament::link :href="$ordersUrl" size="sm">
                        {{ __('Open maintenance orders') }}
                    </x-filament::link>
                </x-slot>
            @endif

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
                                <th class="py-2 pr-4"></th>
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
                                    <td class="py-2 pr-4 text-right">
                                        <x-filament::button
                                            size="xs"
                                            color="warning"
                                            icon="heroicon-o-wrench-screwdriver"
                                            wire:click="mountAction('scheduleMaintenance', { vehicle_id: @js($row['vehicle_id']) })"
                                        >
                                            {{ __('Schedule order') }}
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 2) Licencias y documentos por vencer o vencidos — enlace al recurso del dueño (sin command) --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Licenses & documents expiring') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Driver licenses and fleet documents that are expired or due within 30 days.') }}
            </x-slot>

            @if (count($documents) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('All documents and licenses are up to date.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('Status') }}</th>
                                <th class="py-2 pr-4">{{ __('Type') }}</th>
                                <th class="py-2 pr-4">{{ __('Driver / vehicle') }}</th>
                                <th class="py-2 pr-4">{{ __('Document') }}</th>
                                <th class="py-2 pr-4">{{ __('Expires on') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($documents as $row)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="py-2 pr-4">
                                        @php
                                            $color = match ($row['threshold']) {
                                                \Logistics\Fleet\Application\ReadModel\ExpiringDocumentView::THRESHOLD_EXPIRED => 'danger',
                                                \Logistics\Fleet\Application\ReadModel\ExpiringDocumentView::THRESHOLD_DUE_SOON => 'warning',
                                                default => 'info',
                                            };
                                        @endphp
                                        <x-filament::badge :color="$color">{{ $row['threshold_label'] }}</x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge color="gray">{{ $row['owner_type_label'] }}</x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4 font-medium">
                                        @if ($row['url'] !== null)
                                            <x-filament::link :href="$row['url']">{{ $row['owner_label'] }}</x-filament::link>
                                        @else
                                            {{ $row['owner_label'] }}
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ $row['document_type_label'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['expires_on'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 3) Odómetros atrasados / sin lectura reciente — acción inline: registrar lectura (RecordOdometer) --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Stale odometer readings') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Vehicles without a recent odometer reading.') }}
            </x-slot>
            @if ($readingsUrl !== null)
                <x-slot name="headerEnd">
                    <x-filament::link :href="$readingsUrl" size="sm">
                        {{ __('Open odometer readings') }}
                    </x-filament::link>
                </x-slot>
            @endif

            @if (count($stale) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('All vehicles have recent odometer readings.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('Vehicle') }}</th>
                                <th class="py-2 pr-4">{{ __('Last reading') }}</th>
                                <th class="py-2 pr-4">{{ __('Days since reading') }}</th>
                                <th class="py-2 pr-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stale as $row)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="py-2 pr-4 font-medium">{{ $row['vehicle'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['last_read_at'] ?? __('Never') }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($row['days_since'] === null)
                                            <x-filament::badge color="danger">{{ __('Never') }}</x-filament::badge>
                                        @else
                                            <x-filament::badge color="warning">{{ $row['days_since'] }}</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        <x-filament::button
                                            size="xs"
                                            icon="heroicon-o-arrow-trending-up"
                                            wire:click="mountAction('recordOdometer', { vehicle_id: @js($row['vehicle_id']) })"
                                        >
                                            {{ __('Record reading') }}
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 4) KPI de combustible del día + precio de diésel vigente --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Fuel today') }}
            </x-slot>
            <x-slot name="description">
                {{ $fuel['date'] }}
            </x-slot>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __("Today's fuel spend") }}</dt>
                    <dd class="text-lg font-semibold">{{ $fuel['spend'] }} {{ $fuel['currency'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Active diesel price') }}</dt>
                    <dd class="text-lg font-semibold">{{ $fuel['price'] }} {{ $fuel['price_currency'] }} <span class="text-sm font-normal text-gray-500 dark:text-gray-400">/ {{ __('liter') }}</span></dd>
                </div>
            </dl>
        </x-filament::section>

        {{-- 5) KPIs de costo (TCO y costo por km) — resumen de un vehículo en un rango, movido al final --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Cost summary') }}
            </x-slot>
            <x-slot name="description">
                @if ($summary !== null)
                    {{ $summary['vehicle'] }} · {{ $summary['from'] }} → {{ $summary['to'] }}
                @else
                    {{ __('Use the "Cost summary" action above to calculate TCO and cost per km for a vehicle.') }}
                @endif
            </x-slot>

            @if ($summary !== null)
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
            @endif
        </x-filament::section>

        {{-- 6) Desglose de costos por vehículo / corredor / tercero + rentabilidad por corredor (3-01 / 3-06).
             Se calcula con la acción "Cost & profitability" de la cabecera para un rango de fechas. --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Cost breakdown & profitability') }}
            </x-slot>
            <x-slot name="description">
                @if ($breakdown !== null)
                    {{ $breakdown['from'] }} → {{ $breakdown['to'] }}
                @else
                    {{ __('Use the "Cost & profitability" action above to break down cost by vehicle, corridor and carrier, and see profitability per corridor.') }}
                @endif
            </x-slot>

            @if ($breakdown !== null)
                <div class="space-y-6">
                    {{-- Costo por vehículo --}}
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Cost by vehicle') }}</h3>
                        @if (count($breakdown['vehicles']) === 0)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No cost recorded in this range.') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 dark:text-gray-400">
                                            <th class="py-2 pr-4">{{ __('Vehicle') }}</th>
                                            <th class="py-2 pr-4">{{ __('Fuel') }}</th>
                                            <th class="py-2 pr-4">{{ __('Maintenance') }}</th>
                                            <th class="py-2 pr-4">{{ __('Expenses') }}</th>
                                            <th class="py-2 pr-4">{{ __('Total cost') }}</th>
                                            <th class="py-2 pr-4">{{ __('Kilometers') }}</th>
                                            <th class="py-2 pr-4">{{ __('Cost per km') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($breakdown['vehicles'] as $row)
                                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                                <td class="py-2 pr-4 font-medium">{{ $row['vehicle'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['fuel'] }} {{ $row['currency'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['maintenance'] }} {{ $row['currency'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['expenses'] }} {{ $row['currency'] }}</td>
                                                <td class="py-2 pr-4 font-semibold">{{ $row['total'] }} {{ $row['currency'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['kilometers'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['cost_per_km'] }} {{ $row['currency'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Costo por corredor --}}
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Cost by corridor') }}</h3>
                        @if (count($breakdown['corridors']) === 0)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No departures recorded in this range.') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 dark:text-gray-400">
                                            <th class="py-2 pr-4">{{ __('Corridor') }}</th>
                                            <th class="py-2 pr-4">{{ __('Departures') }}</th>
                                            <th class="py-2 pr-4">{{ __('Kilometers') }}</th>
                                            <th class="py-2 pr-4">{{ __('Deliveries') }}</th>
                                            <th class="py-2 pr-4">{{ __('Total cost') }}</th>
                                            <th class="py-2 pr-4">{{ __('Cost per km') }}</th>
                                            <th class="py-2 pr-4">{{ __('Cost per delivery') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($breakdown['corridors'] as $row)
                                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                                <td class="py-2 pr-4 font-medium">{{ $row['corridor'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['departures'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['kilometers'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['deliveries'] }}</td>
                                                <td class="py-2 pr-4 font-semibold">{{ $row['cost'] }} {{ $row['currency'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['cost_per_km'] }} {{ $row['currency'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['cost_per_delivery'] }} {{ $row['currency'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Costo por tercero (transportista) --}}
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Cost by carrier') }}</h3>
                        @if (count($breakdown['carriers']) === 0)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No carrier settlements in this range.') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 dark:text-gray-400">
                                            <th class="py-2 pr-4">{{ __('Carrier') }}</th>
                                            <th class="py-2 pr-4">{{ __('Settlements') }}</th>
                                            <th class="py-2 pr-4">{{ __('Corridors') }}</th>
                                            <th class="py-2 pr-4">{{ __('Total cost') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($breakdown['carriers'] as $row)
                                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                                <td class="py-2 pr-4 font-medium">{{ $row['carrier'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['settlements'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['corridors'] }}</td>
                                                <td class="py-2 pr-4 font-semibold">{{ $row['cost'] }} {{ $row['currency'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Rentabilidad por corredor (ingreso − costo). Placeholder: sin facturación el margen
                         queda indefinido y se muestra solo el costo, listo para enchufar el ingreso real. --}}
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Profitability by corridor') }}</h3>
                        @unless ($breakdown['profitability']['revenue_available'])
                            <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('Revenue is not configured yet, so margin is unavailable. This view shows cost only and is ready to plug in real revenue once billing exists.') }}
                            </p>
                        @endunless
                        @if (count($breakdown['profitability']['lines']) === 0)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No corridors to show in this range.') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 dark:text-gray-400">
                                            <th class="py-2 pr-4">{{ __('Corridor') }}</th>
                                            <th class="py-2 pr-4">{{ __('Deliveries') }}</th>
                                            <th class="py-2 pr-4">{{ __('Cost') }}</th>
                                            <th class="py-2 pr-4">{{ __('Revenue') }}</th>
                                            <th class="py-2 pr-4">{{ __('Margin') }}</th>
                                            <th class="py-2 pr-4">{{ __('Margin %') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($breakdown['profitability']['lines'] as $row)
                                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                                <td class="py-2 pr-4 font-medium">{{ $row['corridor'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['deliveries'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['cost'] }} {{ $breakdown['profitability']['currency'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['revenue'] }}</td>
                                                <td class="py-2 pr-4 font-semibold">{{ $row['margin'] }}</td>
                                                <td class="py-2 pr-4">{{ $row['margin_ratio'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
