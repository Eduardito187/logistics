{{-- ESCÁNER de etiquetas desde el header: el ícono abre un MODAL (no navega a ninguna página). El modal
     monta el mismo escáner de siempre (cámara + QR/Zebra o código a mano); al cerrarse apaga la cámara
     (la vista escucha `close-modal`). Solo oficina. --}}
<style>
    .dtk-scanbtn { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem;
        height: 2.25rem; border-radius: .625rem; color: rgb(161 161 170); background: transparent;
        border: 1px solid transparent; transition: background .15s, color .15s; margin-inline-end: .1rem;
        cursor: pointer; }
    .dtk-scanbtn:hover { background: rgba(255,255,255,.06); color: rgb(228 228 231); }
</style>
<x-filament::modal id="dtk-scan-label" width="lg">
    <x-slot name="trigger">
        <button type="button" class="dtk-scanbtn" title="{{ __('Scan label') }}" aria-label="{{ __('Scan label') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7V5a1 1 0 0 1 1-1h2" /><path d="M17 4h2a1 1 0 0 1 1 1v2" />
                <path d="M20 17v2a1 1 0 0 1-1 1h-2" /><path d="M7 20H5a1 1 0 0 1-1-1v-2" />
                <path d="M3 12h18" />
            </svg>
        </button>
    </x-slot>

    <x-slot name="heading">{{ __('Scan label') }}</x-slot>

    @include('filament.labels.scan-label-modal')
</x-filament::modal>
