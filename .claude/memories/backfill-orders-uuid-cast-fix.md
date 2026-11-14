# Memoria: Fix del backfill de pedidos — UUID cast error (2026-11-14)

## El bug

`BackfillOrdersFromShipmentsCommand::alreadyBackfilled()` comparaba la clave del grupo contra
`commerce_orders.id` (columna UUID) con `->where('id', $key)`. Cuando la clave era una referencia
numérica de Magento (p.ej. `100000452`), PostgreSQL lanzaba:

```
SQLSTATE[22P02]: Invalid text representation: 7 ERROR:
invalid input syntax for type uuid: "100000452"
```

El error explotaba en el arranque del contenedor (`AUTO_BACKFILL_ORDERS=true` en el entrypoint).

## Causa raíz

PostgreSQL tiene cast estricto de tipos. Comparar una string numérica contra una columna `uuid`
falla siempre. La query hacía `WHERE id = '100000452' OR order_reference = '100000452'` y PG
evaluaba la primera condición antes de cortocircuitar.

## Fix aplicado

En `alreadyBackfilled()`, solo comparar contra `id` si la clave tiene forma de UUID:

```php
$query = OrderRecord::query()->where('order_reference', $key);

if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
    $query->orWhere('id', $key);
}

return $query->exists();
```

## Archivo

`packages/commerce/orders/src/Infrastructure/Console/BackfillOrdersFromShipmentsCommand.php`

## Patrón recurrente

**Siempre que se compare un valor externo/dinámico contra una columna UUID en PostgreSQL**, validar
primero que el valor tiene forma de UUID. De lo contrario, PG lanza `22P02`. Esto aplica a:
- `where('id', $value)` donde `id` es UUID
- `find($value)` donde la PK es UUID
- Cualquier `orWhere` que mezcle columnas UUID con columnas varchar

## Verificación

- Simulación sin `--force`: 385 pedidos detectados, 0 errores
- Con `--force`: 385 creados exitosamente
- Idempotencia: segunda corrida → 385 "already present", 0 creados
