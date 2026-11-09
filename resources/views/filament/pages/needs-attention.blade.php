{{--
    Necesita atención — tablero de triage (cockpit DisTrack OS, Fase 4). Cuatro colas accionables:
    envíos atascados, entregas fallidas sin reprogramar, pedidos pagados sin envío y documentos de flota
    vencidos/por vencer. Datos reales y tolerantes vía App\Support\Cockpit\AttentionQueues ($this->queues).
    Capa `.distrack-cockpit`; español directo; NUNCA UUID visible. Cada fila enlaza al hub que resuelve.
--}}
<x-filament-panels::page>
    @php
        // FQN completos inline (sin `use` en Blade, que compila dentro del método de la vista).
        $shipmentUrl = static fn (string $id): string => \Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource::getUrl('view', ['record' => $id]);
        $orderUrl = static fn (string $id): string => \Commerce\Orders\Presentation\Filament\Resources\OrderResource::getUrl('view', ['record' => $id]);
        $vehicleUrl = static fn (string $id): string => \Logistics\Fleet\Presentation\Filament\Resources\VehicleResource::getUrl('view', ['record' => $id]);
        $driverUrl = static fn (string $id): string => \Logistics\Fleet\Presentation\Filament\Resources\DriverResource::getUrl('view', ['record' => $id]);

        $q = $this->queues;
        $stuck = $q['stuck_shipments'];
        $failed = $q['failed_deliveries'];
        $paid = $q['paid_without_shipment'];
        $docs = $q['expiring_docs'];

        $total = $stuck['count'] + $failed['count'] + $paid['count'] + $docs['count'];

        // Estado de envío → etiqueta legible + tono.
        $shipStatusMap = [
            'RECEIVED' => ['Recibido', ''],
            'PLANNED' => ['Planificado', 'agent'],
            'IN_EXECUTION' => ['En ejecución', 'warning'],
            'DELIVERED' => ['Entregado', 'positive'],
            'FAILED' => ['Fallido', 'danger'],
            'CANCELLED' => ['Cancelado', ''],
        ];
        $shipLabel = static function (string $st) use ($shipStatusMap): array {
            return $shipStatusMap[strtoupper($st)] ?? [ucfirst(strtolower(str_replace('_', ' ', $st))), ''];
        };

        // Umbral de documento → etiqueta + tono + icono.
        $docMap = [
            'expired' => ['Vencido', 'danger', 'ck-event__ic--red'],
            'due_soon' => ['Por vencer', 'warning', 'ck-event__ic--amber'],
            'upcoming' => ['Próximo', '', ''],
        ];

        // Etiqueta legible del tipo de dueño del documento.
        $ownerKind = ['vehicle' => 'Vehículo', 'driver' => 'Chofer', 'helper' => 'Ayudante'];
    @endphp

    <div class="distrack-cockpit">

        {{-- ===== HERO ===== --}}
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Necesita atención</div>
                    <div class="ck-hub__sub">Lo que está trabado o por vencer y necesita acción de la oficina.</div>
                </div>
            </div>
            <div class="ck-hub__aside">
                <span class="ck-pill {{ $total > 0 ? 'ck-pill--danger' : 'ck-pill--positive' }}">
                    <span class="ck-pill__dot"></span>{{ $total > 0 ? $total.' pendientes' : 'Todo al día' }}
                </span>
            </div>
        </div>

        {{-- ===== KPI STRIP ===== --}}
        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat {{ $stuck['count'] > 0 ? 'ck-stat--warning' : 'ck-stat--positive' }}">
                <div class="ck-stat__label">Envíos atascados</div>
                <div class="ck-stat__value">{{ $stuck['count'] }}</div>
                <div class="ck-stat__hint">fecha comprometida vencida</div>
            </div>
            <div class="ck-stat {{ $failed['count'] > 0 ? 'ck-stat--danger' : 'ck-stat--positive' }}">
                <div class="ck-stat__label">Entregas fallidas</div>
                <div class="ck-stat__value">{{ $failed['count'] }}</div>
                <div class="ck-stat__hint">sin reprogramar</div>
            </div>
            <div class="ck-stat {{ $paid['count'] > 0 ? 'ck-stat--warning' : 'ck-stat--positive' }}">
                <div class="ck-stat__label">Pagados sin envío</div>
                <div class="ck-stat__value">{{ $paid['count'] }}</div>
                <div class="ck-stat__hint">el envío no se generó</div>
            </div>
            <div class="ck-stat {{ $docs['count'] > 0 ? 'ck-stat--warning' : 'ck-stat--positive' }}">
                <div class="ck-stat__label">Documentos</div>
                <div class="ck-stat__value">{{ $docs['count'] }}</div>
                <div class="ck-stat__hint">vencidos o por vencer</div>
            </div>
        </div>

        {{-- ===== COLAS ===== --}}
        <div class="ck-grid ck-grid--2 ck-section">

            {{-- Envíos atascados --}}
            <div class="ck-card">
                <div class="ck-card__eyebrow">Envíos atascados · {{ $stuck['count'] }}</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($stuck['items'] as $it)
                        @php [$sl, $stn] = $shipLabel($it['status']); @endphp
                        <div class="ck-event">
                            <span class="ck-event__ic ck-event__ic--amber"></span>
                            <span class="ck-event__tx">
                                <a href="{{ $shipmentUrl($it['id']) }}" wire:navigate class="ck-link">{{ $it['reference'] }}</a>
                                @if($it['customer'])<small>{{ $it['customer'] }}</small>@endif
                            </span>
                            <span class="ck-pill {{ $stn ? 'ck-pill--'.$stn : '' }}">{{ $sl }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Nada pendiente aquí</div><div class="ck-empty__hint">Ningún envío activo pasó su fecha comprometida.</div></div>
                    @endforelse
                </div>
                @if($stuck['count'] > count($stuck['items']))
                    <div class="ck-hub__sub" style="margin-top:.8rem">Mostrando {{ count($stuck['items']) }} de {{ $stuck['count'] }}.</div>
                @endif
            </div>

            {{-- Entregas fallidas sin reprogramar --}}
            <div class="ck-card">
                <div class="ck-card__eyebrow">Entregas fallidas sin reprogramar · {{ $failed['count'] }}</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($failed['items'] as $it)
                        <div class="ck-event">
                            <span class="ck-event__ic ck-event__ic--red"></span>
                            <span class="ck-event__tx">
                                <a href="{{ $shipmentUrl($it['id']) }}" wire:navigate class="ck-link">{{ $it['reference'] }}</a>
                                <small>{{ $it['reason'] }}</small>
                            </span>
                            <span class="ck-pill ck-pill--danger">Fallida</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Nada pendiente aquí</div><div class="ck-empty__hint">No hay entregas fallidas con el envío abierto.</div></div>
                    @endforelse
                </div>
                @if($failed['count'] > count($failed['items']))
                    <div class="ck-hub__sub" style="margin-top:.8rem">Mostrando {{ count($failed['items']) }} de {{ $failed['count'] }}.</div>
                @endif
            </div>

            {{-- Pedidos pagados sin envío --}}
            <div class="ck-card">
                <div class="ck-card__eyebrow">Pedidos pagados sin envío · {{ $paid['count'] }}</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($paid['items'] as $it)
                        <div class="ck-event">
                            <span class="ck-event__ic ck-event__ic--amber"></span>
                            <span class="ck-event__tx">
                                <a href="{{ $orderUrl($it['id']) }}" wire:navigate class="ck-link">{{ $it['customer'] }}</a>
                                @if($it['when'])<small>Pagado {{ $it['when'] }}</small>@endif
                            </span>
                            <span class="ck-pill ck-pill--warning">Sin envío</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Nada pendiente aquí</div><div class="ck-empty__hint">Todo pedido pagado ya generó su envío.</div></div>
                    @endforelse
                </div>
                @if($paid['count'] > count($paid['items']))
                    <div class="ck-hub__sub" style="margin-top:.8rem">Mostrando {{ count($paid['items']) }} de {{ $paid['count'] }}.</div>
                @endif
            </div>

            {{-- Documentos vencidos / por vencer --}}
            <div class="ck-card">
                <div class="ck-card__eyebrow">Documentos vencidos o por vencer · {{ $docs['count'] }}</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($docs['items'] as $it)
                        @php [$dl, $dtn, $dic] = $docMap[$it['threshold']] ?? ['—', '', '']; @endphp
                        <div class="ck-event">
                            <span class="ck-event__ic {{ $dic }}"></span>
                            <span class="ck-event__tx">
                                @if($it['owner_type'] === 'vehicle')
                                    <a href="{{ $vehicleUrl($it['owner_id']) }}" wire:navigate class="ck-link">{{ $it['owner_label'] }}</a>
                                @elseif($it['owner_type'] === 'driver')
                                    <a href="{{ $driverUrl($it['owner_id']) }}" wire:navigate class="ck-link">{{ $it['owner_label'] }}</a>
                                @else
                                    {{ $it['owner_label'] }}
                                @endif
                                <small>{{ $ownerKind[$it['owner_type']] ?? $it['owner_type'] }} · {{ $it['document_type'] }}</small>
                            </span>
                            <span class="ck-pill {{ $dtn ? 'ck-pill--'.$dtn : '' }}">{{ $dl }}</span>
                            <span class="ck-event__when">{{ \Illuminate\Support\Carbon::parse($it['expires_on'])->isoFormat('DD MMM YYYY') }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Nada pendiente aquí</div><div class="ck-empty__hint">Ningún documento de flota vencido ni por vencer.</div></div>
                    @endforelse
                </div>
                @if($docs['count'] > count($docs['items']))
                    <div class="ck-hub__sub" style="margin-top:.8rem">Mostrando {{ count($docs['items']) }} de {{ $docs['count'] }}.</div>
                @endif
            </div>

        </div>
    </div>
</x-filament-panels::page>
