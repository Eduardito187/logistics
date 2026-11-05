{{-- Barra de "causa contribuyente": etiqueta + conteo y una barra cuyo ancho es el peso (0..1) de la
     causa dentro del total en riesgo. El `tone` da el color de la barra (agent por defecto). --}}
@props(['label', 'count' => null, 'weight' => 0, 'tone' => 'agent'])

@php
    // Ancho visible acotado: mínimo 2% para que una causa pequeña siga siendo perceptible.
    $pct = max(2, min(100, (int) round(((float) $weight) * 100)));
@endphp

<div class="ck-bar">
    <span class="ck-bar__label">{{ $label }}</span>
    @if ($count !== null)
        <span class="ck-bar__count">{{ $count }}</span>
    @endif
    <div class="ck-bar__track">
        <div @class([
            'ck-bar__fill',
            'ck-bar__fill--red' => $tone === 'danger',
            'ck-bar__fill--amber' => $tone === 'warning',
        ]) style="width: {{ $pct }}%"></div>
    </div>
</div>
