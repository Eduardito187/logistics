{{-- Tile de KPI del pulso operativo: etiqueta, valor grande (display), y un pie con delta (▲/▼ con
     color) y/o una pista corta. El `tone` tiñe la barra superior (warning/danger/positive/agent). --}}
@props([
    'label',
    'value',
    'delta' => null,     // texto ya formateado, p. ej. "+6.4%"
    'trend' => 'flat',   // up | down | flat
    'tone' => 'neutral', // neutral | warning | danger | positive | agent
    'hint' => null,
])

<div @class(['ck-stat', 'ck-stat--'.$tone => $tone !== 'neutral'])>
    <span class="ck-stat__label">{{ $label }}</span>
    <span class="ck-stat__value">{{ $value }}</span>
    <div class="ck-stat__foot">
        @if ($delta !== null)
            <span @class(['ck-delta', 'ck-delta--up' => $trend === 'up', 'ck-delta--down' => $trend === 'down'])>
                <span aria-hidden="true">{{ $trend === 'up' ? '▲' : ($trend === 'down' ? '▼' : '•') }}</span>
                {{ $delta }}
            </span>
        @endif
        @if ($hint)
            <span class="ck-stat__hint">{{ $hint }}</span>
        @endif
    </div>
</div>
