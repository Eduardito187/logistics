{{--
    Listado de "Otros pedidos de este cliente": envíos con el mismo customer_name, cada uno enlazado
    a su página de detalle (se abre en otra pestaña). La consulta se resuelve en el resource
    (ShipmentResource::otherOrdersFor) para no meter lógica de datos en la vista.
--}}
@php
    use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

    /** @var \Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord|null $record */
    $record = $getRecord();
    $orders = $record ? ShipmentResource::otherOrdersFor($record) : collect();
@endphp

<div>
    @if ($orders->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('No other orders from this customer.') }}
        </p>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($orders as $order)
                <li class="flex items-center justify-between gap-4 py-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                            {{ $order->external_id }}
                            <span class="text-gray-400">·</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $order->status }}</span>
                        </p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ $order->address_text }}
                        </p>
                    </div>
                    <a
                        href="{{ ShipmentResource::getUrl('view', ['record' => $order->getKey()]) }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ __('View order') }} &rarr;
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
