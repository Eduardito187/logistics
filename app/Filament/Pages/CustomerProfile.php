<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Ficha completa del cliente: contrato compartido entre frentes. El frente shipments la CREA y el
 * frente execution solo ENLAZA a ella. Recibe el nombre del cliente por querystring
 * (?customer=<nombre>) y muestra sus datos básicos (documento, teléfono, correo), todos sus pedidos
 * (cada uno enlazado a su detalle en una pestaña nueva), sus direcciones de entrega y un mapa
 * Leaflet + OpenStreetMap con todas esas ubicaciones.
 *
 * No aparece en el menú (se accede por enlace desde el pedido): shouldRegisterNavigation = false.
 * Todo el texto visible va en inglés dentro de __() (regla 5 de CLAUDE.md).
 */
final class CustomerProfile extends Page
{
    // Ficha de cliente: alcance de oficina (admin/supervisor), igual que el resto del frente Commerce.
    // No se lista en el menú (shouldRegisterNavigation = false), pero igual se gatea el acceso directo.
    use OfficeOnly;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected string $view = 'filament.pages.customer-profile';

    /** Nombre del cliente recibido por querystring; es la clave de agrupación de los pedidos. */
    public ?string $customer = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Pertenece al frente Commerce (pedidos y clientes) aunque no se liste en el menú.
    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    /**
     * Lee el nombre del cliente del querystring (?customer=<nombre>). Lo normaliza a null si no viene.
     */
    public function mount(): void
    {
        $customer = request()->query('customer');
        $this->customer = is_string($customer) && trim($customer) !== '' ? $customer : null;
    }

    public function getTitle(): string
    {
        return $this->customer ?? __('Customer profile');
    }

    public function getHeading(): string
    {
        return $this->customer ?? __('Customer profile');
    }

    public function getSubheading(): string
    {
        return __('Full customer details, orders and delivery addresses.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $orders = $this->orders();
        $representative = $orders->first();

        return [
            'customerName' => $this->customer,
            'hasCustomer' => $this->customer !== null && $orders->isNotEmpty(),
            'documentId' => $representative?->customer_document_id,
            'phone' => $representative?->customer_phone,
            'email' => $representative?->customer_email,
            'orders' => $orders,
            'ordersCount' => $orders->count(),
            'addresses' => $this->addresses($orders),
            'mapPoints' => $this->mapPoints($orders),
        ];
    }

    /**
     * Pedidos del cliente (por nombre exacto), más recientes primero. Acotado para no cargar de más.
     *
     * @return Collection<int, ShipmentRecord>
     */
    private function orders(): Collection
    {
        if ($this->customer === null) {
            /** @var Collection<int, ShipmentRecord> */
            return collect();
        }

        return ShipmentRecord::query()
            ->where('customer_name', $this->customer)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    /**
     * Direcciones de entrega únicas del cliente (texto + referencia + coordenadas, con el conteo de
     * pedidos a cada una), derivadas de sus pedidos.
     *
     * @param  Collection<int, ShipmentRecord>                                                     $orders
     * @return list<array{text: string, reference: ?string, lat: ?float, lng: ?float, count: int}>
     */
    private function addresses(Collection $orders): array
    {
        $byText = [];
        foreach ($orders as $order) {
            $key = (string) $order->address_text;
            if (!isset($byText[$key])) {
                $byText[$key] = [
                    'text' => $key,
                    'reference' => $order->address_reference,
                    'lat' => $order->lat !== null ? (float) $order->lat : null,
                    'lng' => $order->lng !== null ? (float) $order->lng : null,
                    'count' => 0,
                ];
            }
            $byText[$key]['count']++;
        }

        return array_values($byText);
    }

    /**
     * Puntos geolocalizados para el mapa: solo las direcciones que tienen coordenadas.
     *
     * @param  Collection<int, ShipmentRecord>                   $orders
     * @return list<array{lat: float, lng: float, text: string}>
     */
    private function mapPoints(Collection $orders): array
    {
        $points = [];
        $seen = [];
        foreach ($orders as $order) {
            if ($order->lat === null || $order->lng === null) {
                continue;
            }
            $key = $order->lat.','.$order->lng;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $points[] = [
                'lat' => (float) $order->lat,
                'lng' => (float) $order->lng,
                'text' => (string) $order->address_text,
            ];
        }

        return $points;
    }

    /**
     * URL de la ficha para un cliente dado. Lo expone como enlace (contrato con el frente execution).
     */
    public static function urlFor(string $customerName): string
    {
        return self::getUrl(['customer' => $customerName]);
    }
}
