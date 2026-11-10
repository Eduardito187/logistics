{{-- Tarjeta de "agente" (inteligencia operativa): eyebrow con marca del agente + un badge de confianza
     opcional, sobre un degradado violeta sutil. El contenido (titular, causas, propuestas) va en el slot.

     Importante: NO es IA de verdad; es inteligencia derivada de datos reales (ventanas de promesa × ETA)
     presentada como asistente. El texto debe ser honesto (ver OperationsBrief del backend). --}}
@props(['eyebrow' => 'Operations agent', 'confidence' => null])

<section {{ $attributes->class(['ck-agentcard']) }}>
    <div class="flex items-center justify-between gap-2">
        <span class="ck-agentcard__eyebrow">
            {{-- Chispa inline (sin dependencia de icon components; el admin los desactiva). --}}
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
                <path d="M12 2l1.8 4.9L18.7 8l-4.9 1.8L12 14.7 10.2 9.8 5.3 8l4.9-1.1L12 2zM19 14l.9 2.5 2.5.9-2.5.9-.9 2.5-.9-2.5-2.5-.9 2.5-.9L19 14z"/>
            </svg>
            {{ $eyebrow }}
        </span>
        @if ($confidence !== null)
            <x-cockpit.pill tone="agent" :dot="false">{{ $confidence }}</x-cockpit.pill>
        @endif
    </div>

    <div class="mt-3">{{ $slot }}</div>
</section>
