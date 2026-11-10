{{-- Métrica de telemetría (tira del hero): etiqueta corta + valor monoespaciado. `tone=danger` lo tiñe
     de rojo (p. ej. retraso de ETA). --}}
@props(['label', 'value', 'tone' => null])

<div class="ck-metric">
    <p class="ck-metric__label">{{ $label }}</p>
    <p class="ck-metric__value" @if ($tone === 'danger') style="color: var(--ck-red)" @endif>{{ $value }}</p>
</div>
