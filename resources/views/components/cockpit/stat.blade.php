{{-- Tile de KPI del pulso operativo: etiqueta, valor grande (display), y un pie con delta (▲/▼ con
     color) y/o una pista corta. El `tone` tiñe la barra superior (warning/danger/positive/agent).

     El FLECHA del delta muestra la dirección real del cambio (▲ subió / ▼ bajó vs la foto anterior); el
     COLOR lo da `deltaTone` (verde = mejoró, rojo = empeoró, neutro = ni bueno ni malo), porque "subir"
     no siempre es bueno (p. ej. más demorados es malo). Sin línea base histórica, `delta` va null. --}}
@props([
    'label',
    'value',
    'delta' => null,        // texto ya formateado, p. ej. "+3" o "−2" (null si no hay línea base)
    'trend' => 'flat',      // up | down | flat  → dirección real del cambio (flecha)
    'deltaTone' => 'neutral', // positive | negative | neutral → color del delta (bueno/malo)
    'tone' => 'neutral',    // neutral | warning | danger | positive | agent → barra del tile
    'hint' => null,
])

<div @class(['ck-stat', 'ck-stat--'.$tone => $tone !== 'neutral'])>
    <span class="ck-stat__label">{{ $label }}</span>
    <span class="ck-stat__value">{{ $value }}</span>
    <div class="ck-stat__foot">
        @if ($delta !== null)
            <span @class([
                'ck-delta',
                'ck-delta--good' => $deltaTone === 'positive',
                'ck-delta--bad' => $deltaTone === 'negative',
            ])>
                <span aria-hidden="true">{{ $trend === 'up' ? '▲' : ($trend === 'down' ? '▼' : '•') }}</span>
                {{ $delta }}
                <span class="ck-delta__since">{{ __('vs prev.') }}</span>
            </span>
        @endif
        @if ($hint)
            <span class="ck-stat__hint">{{ $hint }}</span>
        @endif
    </div>
</div>
