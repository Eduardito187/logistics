{{-- Acceso directo al ESCÁNER de etiquetas desde el header (cámara). Un botón simple e intuitivo, al lado
     de las notificaciones y la búsqueda. Solo oficina. --}}
<a href="{{ \Logistics\Labels\Presentation\Filament\Pages\ScanLabelPage::getUrl() }}"
   class="dtk-scanbtn" title="{{ __('Scan label') }}" aria-label="{{ __('Scan label') }}">
    <style>
        .dtk-scanbtn { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem;
            height: 2.25rem; border-radius: .625rem; color: rgb(161 161 170); background: transparent;
            border: 1px solid transparent; transition: background .15s, color .15s; margin-inline-end: .1rem; }
        .dtk-scanbtn:hover { background: rgba(255,255,255,.06); color: rgb(228 228 231); }
    </style>
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 7V5a1 1 0 0 1 1-1h2" /><path d="M17 4h2a1 1 0 0 1 1 1v2" />
        <path d="M20 17v2a1 1 0 0 1-1 1h-2" /><path d="M7 20H5a1 1 0 0 1-1-1v-2" />
        <path d="M3 12h18" />
    </svg>
</a>
