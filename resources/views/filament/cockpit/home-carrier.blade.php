{{--
    Home del TRANSPORTISTA (DisTrack OS, Fase 4 — homes por rol). "Liquidaciones de transporte":
    pendientes (y monto) y las más recientes, con el transportista resuelto por nombre (nunca UUID).
    Informativo. Datos de App\Support\Cockpit\CarrierHome. Se renderiza dentro del cockpit de ControlTower.
--}}
@php
    /** @var array<string, mixed> $h */
    $sym = static fn (string $c): string => match (strtoupper($c)) { 'BOB' => 'Bs', 'USD' => '$', default => $c };
    $money = static fn (int $cents, string $c): string => $sym($c).' '.number_format($cents / 100, 2);
    $statusLabel = static fn (string $s): string => match (strtolower($s)) {
        'pending' => 'Pendiente',
        'approved' => 'Aprobada',
        'disputed' => 'En disputa',
        'paid', 'settled' => 'Pagada',
        'cancelled' => 'Anulada',
        default => ucfirst(strtolower($s)),
    };
    $statusTone = static fn (string $s): string => match (strtolower($s)) {
        'paid', 'settled' => 'positive',
        'disputed' => 'danger',
        'approved' => 'agent',
        default => '',
    };
@endphp
<div class="ck-section">
    <div class="ck-hub__head" style="margin-bottom:1rem">
        <div style="min-width:0">
            <div class="ck-hub__title" style="font-size:1.4rem">Liquidaciones de transporte</div>
            <div class="ck-hub__sub">Pendientes de pago y actividad reciente</div>
        </div>
        <div class="ck-hub__aside">
            <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ number_format($h['open']) }} pendientes</span>
        </div>
    </div>

    <div class="ck-grid ck-grid--4">
        <div class="ck-stat ck-stat--warning">
            <div class="ck-stat__label">Pendientes</div>
            <div class="ck-stat__value">{{ number_format($h['open']) }}</div>
        </div>
        <div class="ck-stat ck-stat--agent">
            <div class="ck-stat__label">Monto pendiente</div>
            <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($h['open_amount_cents'], $h['currency']) }}</div>
        </div>
        <div class="ck-stat">
            <div class="ck-stat__label">Transportistas</div>
            <div class="ck-stat__value">{{ number_format($h['carriers']) }}</div>
        </div>
    </div>

    <div class="ck-card" style="margin-top:1rem">
        <div class="ck-card__eyebrow">Liquidaciones recientes</div>
        <div class="ck-events" style="margin-top:.6rem">
            @forelse($h['recent'] as $s)
                <div class="ck-event">
                    <span class="ck-event__ic {{ $statusTone($s['status']) === 'danger' ? 'ck-event__ic--red' : '' }}"></span>
                    <span class="ck-event__tx">
                        {{ $s['carrier'] }}
                        <small>{{ $s['period'] }}</small>
                    </span>
                    <span class="ck-event__when">{{ $money($s['amount_cents'], $s['currency']) }}</span>
                    <span class="ck-pill {{ $statusTone($s['status']) ? 'ck-pill--'.$statusTone($s['status']) : '' }}">{{ $statusLabel($s['status']) }}</span>
                </div>
            @empty
                <div class="ck-empty">
                    <div class="ck-empty__title">Sin liquidaciones</div>
                    <div class="ck-empty__hint">Cuando haya liquidaciones de transporte, las vas a ver acá.</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
