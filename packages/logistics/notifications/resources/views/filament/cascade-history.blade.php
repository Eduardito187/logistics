{{-- Historial de versiones de la clave de cascada (notifications.cascade_rules), de la más nueva a la
     más vieja. Es solo lectura: cada fila es una versión inmutable publicada por el bus CQRS. --}}
@php
    /** @var list<array{version: int, effective_from: string, created_at: string, created_by: string|null, rule_count: int}> $versions */
@endphp

@if (empty($versions))
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('No versions have been published yet. The default ADR 006 cascade applies to every message type.') }}
    </p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                    <th class="py-2 pr-4 font-medium">{{ __('Version') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Customized types') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Effective from') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Published at') }}</th>
                    <th class="py-2 font-medium">{{ __('By') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($versions as $version)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-2 pr-4 font-mono">#{{ $version['version'] }}</td>
                        <td class="py-2 pr-4">{{ $version['rule_count'] }}</td>
                        <td class="py-2 pr-4">{{ $version['effective_from'] }}</td>
                        <td class="py-2 pr-4">{{ $version['created_at'] }}</td>
                        <td class="py-2">{{ $version['created_by'] ?? __('Unknown') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
