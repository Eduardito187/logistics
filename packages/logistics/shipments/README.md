# logistics/shipments — Recepción de envíos

## Responsabilidad

API única de ingreso de pedidos para entregar (SAP, Magento, tiendas, carga manual o Excel; dis-commerce más adelante). Normaliza cada pedido al modelo propio: envío, líneas y unidades físicas. Geocodifica y puntúa la confianza de la dirección.

## No es responsable de

Validar stock (decisión de negocio: solo se reciben pedidos para entregar). Hablar con SAP directamente: eso es del conector.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
