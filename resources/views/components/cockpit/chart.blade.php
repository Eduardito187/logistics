{{-- Gráfico del cockpit (SVG auto-hospedado, cero CDN; dibujado a escala en PHP). Tipos: line, area,
     bars, sparkline, donut. Datos primitivos; tolerante a series vacías. Colores por `tone` desde los
     tokens --ck-*. Usar: <x-cockpit.chart type="area" :series="[1,2,3]" :labels="['a','b']" tone="agent"/> --}}
@props([
    'type' => 'line',          // line | area | bars | sparkline | donut
    'series' => [],            // list<int|float>
    'labels' => [],            // list<string> (eje x)
    'tone' => 'agent',         // agent | red | green | amber
    'min' => null,             // límite inferior del eje (auto si null)
    'max' => null,             // límite superior del eje (auto si null)
    'height' => 120,
    'label' => null,           // etiqueta superior
    'value' => null,           // valor grande superior (texto ya formateado)
    'unit' => null,            // sufijo del valor
    'yticks' => true,          // mostrar 2 marcas en el eje y
])

@php
    $vals = array_values(array_map(static fn ($v): float => (float) $v, $series));
    $n = count($vals);
    $lo = $min !== null ? (float) $min : ($n ? min($vals) : 0.0);
    $hi = $max !== null ? (float) $max : ($n ? max($vals) : 1.0);
    if ($hi <= $lo) {
        $hi = $lo + 1.0; // evita división por cero cuando todos los valores son iguales
    }
    $toneCls = in_array($tone, ['red', 'green', 'amber'], true) ? '--'.$tone : '';

    // Geometría (coordenadas del viewBox).
    $W = 300; $H = max(40, (int) $height);
    $padL = 26; $padR = 8; $padT = 10; $padB = ($type === 'sparkline' ? 4 : 20);
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT;
    $mapX = fn (int $i): float => $n <= 1 ? ($x0 + $x1) / 2 : $x0 + ($x1 - $x0) * ($i / ($n - 1));
    $mapY = fn (float $v): float => $y0 - ($y0 - $y1) * (($v - $lo) / ($hi - $lo));

    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = [round($mapX($i), 1), round($mapY($v), 1)];
    }
    $poly = implode(' ', array_map(static fn ($p): string => $p[0].','.$p[1], $pts));
    $areaPath = $pts !== []
        ? 'M'.$pts[0][0].','.$y0.' L'.implode(' L', array_map(static fn ($p): string => $p[0].','.$p[1], $pts)).' L'.$pts[count($pts) - 1][0].','.$y0.' Z'
        : '';

    $gid = 'ckg'.substr(md5($poly.$tone.$type.(string) $value), 0, 8);
    $fmtN = static fn (float $v): string => rtrim(rtrim(number_format($v, ($v == (int) $v ? 0 : 1)), '0'), '.');
@endphp

<div {{ $attributes->class(['ck-chart']) }}>
    @if ($label || $value !== null)
        <div class="ck-chart__head">
            @if ($label)<span class="ck-chart__label">{{ $label }}</span>@endif
            @if ($value !== null)<span class="ck-chart__big">{{ $value }}@if ($unit)<span class="u">{{ $unit }}</span>@endif</span>@endif
        </div>
    @endif

    @if ($n === 0)
        <div class="ck-empty" style="padding:1.2rem"><p class="ck-empty__hint">Sin datos para graficar.</p></div>
    @elseif ($type === 'donut')
        @php
            $val = (float) ($vals[0] ?? 0);
            $cap = $max !== null ? (float) $max : ($hi);
            $pct = $cap > 0 ? max(0, min(1, $val / $cap)) : 0;
            $r = 54; $circ = 2 * M_PI * $r;
        @endphp
        <svg viewBox="0 0 140 140" style="height:{{ $H }}px" role="img" aria-label="{{ $label }} {{ $value }}">
            <circle class="ck-donut__track" cx="70" cy="70" r="{{ $r }}"></circle>
            <circle class="ck-donut__val {{ $toneCls ? 'ck-line'.$toneCls : '' }}" cx="70" cy="70" r="{{ $r }}"
                stroke="{{ $tone === 'red' ? 'var(--ck-red)' : ($tone === 'green' ? 'var(--ck-green)' : ($tone === 'amber' ? 'var(--ck-amber)' : 'var(--ck-agent)')) }}"
                stroke-dasharray="{{ round($circ, 2) }}" stroke-dashoffset="{{ round($circ * (1 - $pct), 2) }}"></circle>
            <text x="70" y="74" text-anchor="middle" style="font-family:var(--distrack-font-display);font-size:22px;fill:var(--ck-ink)">{{ round($pct * 100) }}%</text>
        </svg>
    @elseif ($type === 'bars')
        @php $bw = ($x1 - $x0) / max(1, $n) * 0.62; @endphp
        <svg viewBox="0 0 {{ $W }} {{ $H }}" style="height:{{ $H }}px" role="img" aria-label="{{ $label }}">
            <line class="ck-axis" x1="{{ $x0 }}" y1="{{ $y0 }}" x2="{{ $x1 }}" y2="{{ $y0 }}"></line>
            @foreach ($pts as $i => $p)
                <rect class="ck-bar {{ $toneCls ? 'ck-bar'.$toneCls : '' }}" x="{{ round($p[0] - $bw / 2, 1) }}" y="{{ $p[1] }}"
                    width="{{ round($bw, 1) }}" height="{{ round($y0 - $p[1], 1) }}" rx="3"></rect>
                @if (isset($labels[$i]))<text x="{{ $p[0] }}" y="{{ $H - 6 }}" text-anchor="middle">{{ \Illuminate\Support\Str::limit($labels[$i], 6, '') }}</text>@endif
            @endforeach
        </svg>
    @elseif ($type === 'sparkline')
        <svg class="ck-spark" viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" style="height:{{ $H }}px" role="img" aria-label="{{ $label }}">
            <polyline class="ck-line {{ $toneCls ? 'ck-line'.$toneCls : '' }}" points="{{ $poly }}"></polyline>
        </svg>
    @else
        {{-- line / area --}}
        <svg viewBox="0 0 {{ $W }} {{ $H }}" style="height:{{ $H }}px" role="img" aria-label="{{ $label }}">
            <defs>
                <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="{{ $tone === 'red' ? 'var(--ck-red)' : ($tone === 'green' ? 'var(--ck-green)' : ($tone === 'amber' ? 'var(--ck-amber)' : 'var(--ck-agent)')) }}" stop-opacity=".32"></stop>
                    <stop offset="1" stop-color="var(--ck-card)" stop-opacity="0"></stop>
                </linearGradient>
            </defs>
            <line class="ck-grid-l" x1="{{ $x0 }}" y1="{{ round($y1 + ($y0 - $y1) / 2, 1) }}" x2="{{ $x1 }}" y2="{{ round($y1 + ($y0 - $y1) / 2, 1) }}"></line>
            <line class="ck-axis" x1="{{ $x0 }}" y1="{{ $y0 }}" x2="{{ $x1 }}" y2="{{ $y0 }}"></line>
            @if ($type === 'area' && $areaPath)<path d="{{ $areaPath }}" fill="url(#{{ $gid }})"></path>@endif
            <polyline class="ck-line {{ $toneCls ? 'ck-line'.$toneCls : '' }}" points="{{ $poly }}"></polyline>
            @if (! empty($pts))<circle class="ck-dot {{ $toneCls ? 'ck-bar'.$toneCls : '' }}" cx="{{ $pts[count($pts) - 1][0] }}" cy="{{ $pts[count($pts) - 1][1] }}" r="3"></circle>@endif
            @if ($yticks)
                <text x="2" y="{{ $y1 + 4 }}">{{ $fmtN($hi) }}</text>
                <text x="2" y="{{ $y0 }}">{{ $fmtN($lo) }}</text>
            @endif
            @if (isset($labels[0]))<text x="{{ $x0 }}" y="{{ $H - 4 }}">{{ $labels[0] }}</text>@endif
            @if (count($labels) > 1)<text x="{{ $x1 }}" y="{{ $H - 4 }}" text-anchor="end">{{ $labels[count($labels) - 1] }}</text>@endif
        </svg>
    @endif
</div>
