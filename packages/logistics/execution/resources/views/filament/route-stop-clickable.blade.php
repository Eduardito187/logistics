{{-- Hace clickeable TODO el recuadro de la parada (el contenedor .fi-in-repeatable-item que la envuelve): --}}
{{-- al hacer clic en cualquier parte de la tarjeta se centra y abre, en el mapa de la ruta de arriba, el --}}
{{-- marcador de esa parada (vía window.focusRouteStop expuesta por el mapa). Los clics sobre links o --}}
{{-- botones de la tarjeta (Pedido, Cliente) se respetan y siguen navegando. Solo se hacen clickeables las --}}
{{-- paradas con ubicación en el mapa (hasPoint); las no geocodificadas quedan como tarjeta normal. Este div --}}
{{-- es solo el cableado (va oculto) y corre una vez por tarjeta. --}}
@php($seq = (int) ($seq ?? 0))
@if (! empty($hasPoint))
    <div
        x-data
        x-init="
            const card = $el.closest('.fi-in-repeatable-item');
            if (card && ! card.dataset.mapClickBound) {
                card.dataset.mapClickBound = '1';
                card.classList.add('cursor-pointer');
                card.setAttribute('role', 'button');
                card.setAttribute('tabindex', '0');
                const go = () => { if (typeof window.focusRouteStop === 'function') window.focusRouteStop({{ $seq }}); };
                card.addEventListener('click', (e) => { if (! e.target.closest('a, button')) go(); });
                card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
            }
        "
        class="hidden"
    ></div>
@endif
