{{-- Fila de propuesta (acción que necesita tu aprobación): etiqueta + meta a la izquierda, acción
     (botón Aprobar o estado) en el slot a la derecha. --}}
@props(['label', 'meta' => null])

<div class="ck-proposal">
    <div class="min-w-0">
        <p class="ck-proposal__label">{{ $label }}</p>
        @if ($meta)
            <p class="ck-proposal__meta">{{ $meta }}</p>
        @endif
    </div>
    <div class="shrink-0">{{ $slot }}</div>
</div>
