{{--
    Home de la TIENDA (DisTrack OS, Fase 4 — homes por rol). "Mi tienda": envíos destinados a su nodo por
    estado y los más recientes. Informativo (la tienda opera desde su navegación). Datos de
    App\Support\Cockpit\StoreHome. Se renderiza dentro de la superficie cockpit de ControlTower.
--}}
@php
    /** @var array<string, mixed> $h */
    $c = $h['counts'];
    $statusLabel = static fn (string $s): string => match (strtoupper($s)) {
        'RECEIVED' => 'Recibido',
        'PLANNED' => 'Planificado',
        'IN_EXECUTION' => 'En camino',
        'DELIVERED' => 'Entregado',
        'FAILED' => 'Fallido',
        'CANCELLED' => 'Cancelado',
        default => ucfirst(strtolower($s)),
    };
    $statusIc = static fn (string $s): string => match (strtoupper($s)) {
        'DELIVERED' => '',
        'FAILED', 'CANCELLED' => 'ck-event__ic--red',
        'IN_EXECUTION' => 'ck-event__ic--amber',
        default => '',
    };
@endphp
<div class="ck-section">
    <div class="ck-hub__head" style="margin-bottom:1rem">
        <div style="min-width:0">
            <div class="ck-hub__title" style="font-size:1.4rem">{{ $h['node_name'] ?? 'Mi tienda' }}</div>
            <div class="ck-hub__sub">
                @if($h['node_code'])<span class="ck-mono">{{ $h['node_code'] }}</span> · @endif Envíos destinados a tu tienda
            </div>
        </div>
        <div class="ck-hub__aside">
            <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ number_format($c['active']) }} activos</span>
        </div>
    </div>

    <div class="ck-grid ck-grid--4">
        <div class="ck-stat">
            <div class="ck-stat__label">Recibidos</div>
            <div class="ck-stat__value">{{ number_format($c['received']) }}</div>
        </div>
        <div class="ck-stat ck-stat--agent">
            <div class="ck-stat__label">Planificados</div>
            <div class="ck-stat__value">{{ number_format($c['planned']) }}</div>
        </div>
        <div class="ck-stat ck-stat--warning">
            <div class="ck-stat__label">En camino</div>
            <div class="ck-stat__value">{{ number_format($c['in_execution']) }}</div>
        </div>
        <div class="ck-stat ck-stat--positive">
            <div class="ck-stat__label">Entregados</div>
            <div class="ck-stat__value">{{ number_format($c['delivered']) }}</div>
        </div>
    </div>

    <div class="ck-card" style="margin-top:1rem">
        <div class="ck-card__eyebrow">Envíos recientes a tu tienda</div>
        <div class="ck-events" style="margin-top:.6rem">
            @forelse($h['recent'] as $s)
                <div class="ck-event">
                    <span class="ck-event__ic {{ $statusIc($s['status']) }}"></span>
                    <span class="ck-event__tx">
                        {{ $s['customer'] }}
                        <small>{{ $s['ref'] }}{{ $s['window'] ? ' · '.$s['window'] : '' }}</small>
                    </span>
                    <span class="ck-event__when">{{ $statusLabel($s['status']) }}</span>
                </div>
            @empty
                <div class="ck-empty">
                    <div class="ck-empty__title">Sin envíos a tu tienda</div>
                    <div class="ck-empty__hint">Cuando lleguen pedidos destinados a tu tienda, los vas a ver acá.</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
