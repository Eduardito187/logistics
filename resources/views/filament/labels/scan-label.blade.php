<x-filament-panels::page>
    {{-- El escáner ya no se embebe en la página: es un MODAL (ScanLabelAction) con switch de cámara. Esta
         página es solo el acceso desde el menú/header; el botón de abajo monta la MISMA acción del encabezado. --}}
    <div style="max-width:32rem;margin-top:1rem;border-radius:1rem;border:1px solid var(--ck-border,rgba(255,255,255,.1));
        background:var(--ck-surface,#111113);padding:1.75rem 1.5rem;text-align:center">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:3.25rem;height:3.25rem;
            border-radius:999px;background:rgba(227,6,19,.12);color:#e30613;margin-bottom:.9rem">
            <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7V5a1 1 0 0 1 1-1h2" /><path d="M17 4h2a1 1 0 0 1 1 1v2" />
                <path d="M20 17v2a1 1 0 0 1-1 1h-2" /><path d="M7 20H5a1 1 0 0 1-1-1v-2" /><path d="M3 12h18" />
            </svg>
        </div>
        <p style="font-size:.9rem;color:var(--ck-muted,#a1a1aa);margin-bottom:1.1rem">
            {{ __('Open the scanner to turn on the camera and read a label QR or Zebra barcode, or type the code by hand.') }}
        </p>
        <button type="button" wire:click="mountAction('scanLabel')"
            style="border-radius:.6rem;padding:.6rem 1.25rem;font-weight:600;font-size:.9rem;cursor:pointer;
            border:1px solid transparent;background:#e30613;color:#fff">
            {{ __('Scan label') }}
        </button>
    </div>
</x-filament-panels::page>
