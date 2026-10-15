{{-- Panel de Integraciones: estado por integración configurable + estado solo-lectura de geocoder y
     notificaciones. Los botones "Configure" viven en las acciones de cabecera de la página. Texto
     visible en inglés (regla 5). --}}
<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Integraciones configurables --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Routing') }}</x-slot>
            <x-slot name="description">{{ __('OSRM and VROOM road routing engines.') }}</x-slot>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$routing['enabled'] ? 'success' : 'gray'">
                            {{ $routing['enabled'] ? __('Enabled') : __('Disabled') }}
                        </x-filament::badge>
                        @unless ($routing['configured'])
                            <span class="text-gray-400">{{ __('(using environment defaults)') }}</span>
                        @endunless
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Backend') }}</dt>
                    <dd class="font-medium">{{ $routing['backend'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('OSRM host') }}</dt>
                    <dd class="font-medium break-all">{{ $routing['osrm_host'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('VROOM URL') }}</dt>
                    <dd class="font-medium break-all">{{ $routing['vroom_url'] ?: '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('SAP connector') }}</x-slot>
            <x-slot name="description">{{ __('Endpoint and credentials for the SAP connector.') }}</x-slot>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$sap['enabled'] ? 'success' : 'gray'">
                            {{ $sap['enabled'] ? __('Enabled') : __('Disabled') }}
                        </x-filament::badge>
                        @unless ($sap['configured'])
                            <span class="text-gray-400">{{ __('(using environment defaults)') }}</span>
                        @endunless
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Base URL') }}</dt>
                    <dd class="font-medium break-all">{{ $sap['base_url'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Username') }}</dt>
                    <dd class="font-medium">{{ $sap['username'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Password') }}</dt>
                    <dd class="font-medium">{{ $sap['has_password'] ? __('•••• set') : __('Not set') }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('Storage') }}</x-slot>
            <x-slot name="description">{{ __('S3-compatible storage for documents and evidence.') }}</x-slot>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$storage['enabled'] ? 'success' : 'gray'">
                            {{ $storage['enabled'] ? __('Enabled') : __('Disabled') }}
                        </x-filament::badge>
                        @unless ($storage['configured'])
                            <span class="text-gray-400">{{ __('(using environment defaults)') }}</span>
                        @endunless
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Region') }}</dt>
                    <dd class="font-medium">{{ $storage['region'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Default bucket') }}</dt>
                    <dd class="font-medium">{{ $storage['bucket'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Endpoint') }}</dt>
                    <dd class="font-medium break-all">{{ $storage['endpoint'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Access key') }}</dt>
                    <dd class="font-medium">{{ $storage['has_key'] ? __('•••• set') : __('Not set') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Secret key') }}</dt>
                    <dd class="font-medium">{{ $storage['has_secret'] ? __('•••• set') : __('Not set') }}</dd>
                </div>
            </dl>
        </x-filament::section>

        {{-- Estado solo lectura: geocoder y notificaciones los administra otra parte del panel. --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Geocoder & maps (read-only)') }}</x-slot>
            <x-slot name="description">{{ __('Managed elsewhere. Shown here for reference only.') }}</x-slot>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Geocoder driver') }}</dt>
                    <dd class="font-medium">{{ $geocoder['driver'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Nominatim') }}</dt>
                    <dd>
                        <x-filament::badge :color="$geocoder['nominatim_enabled'] ? 'success' : 'gray'">
                            {{ $geocoder['nominatim_enabled'] ? __('Enabled') : __('Disabled') }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Nominatim endpoint') }}</dt>
                    <dd class="font-medium break-all">{{ $geocoder['nominatim_endpoint'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Map tiles URL') }}</dt>
                    <dd class="font-medium break-all">{{ $geocoder['tile_url'] ?: '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('Notifications (read-only)') }}</x-slot>
            <x-slot name="description">{{ __('Managed elsewhere. Shown here for reference only.') }}</x-slot>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Push (ntfy)') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$notifications['ntfy'] ? 'success' : 'gray'">
                            {{ $notifications['ntfy'] ? __('Configured') : __('Not configured') }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Web push (VAPID)') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$notifications['web_push'] ? 'success' : 'gray'">
                            {{ $notifications['web_push'] ? __('Configured') : __('Not configured') }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Telegram') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$notifications['telegram'] ? 'success' : 'gray'">
                            {{ $notifications['telegram'] ? __('Configured') : __('Not configured') }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('SMS gateway') }}:</dt>
                    <dd>
                        <x-filament::badge :color="$notifications['sms'] ? 'success' : 'gray'">
                            {{ $notifications['sms'] ? __('Configured') : __('Not configured') }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Email mailer') }}</dt>
                    <dd class="font-medium">{{ $notifications['email_mailer'] ?: __('Default') }}</dd>
                </div>
            </dl>
        </x-filament::section>

    </div>
</x-filament-panels::page>
