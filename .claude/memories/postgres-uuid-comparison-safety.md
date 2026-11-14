# Memoria: PostgreSQL — comparar valores externos contra columnas UUID (patrón defensivo)

## Regla

PostgreSQL tiene **cast estricto**: comparar una string no-UUID contra una columna `uuid` lanza
`SQLSTATE[22P02]: Invalid text representation`. Esto ocurre incluso si la condición está en un
`orWhere` (PG evalúa ambos lados).

## Patrón defensivo

Antes de comparar contra una columna UUID, validar la forma:

```php
// CORRECTO: solo compara si tiene forma UUID
$query = Model::query()->where('varchar_column', $key);
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
    $query->orWhere('id', $key);  // id es uuid
}

// INCORRECTO: explota si $key = '100000452'
Model::query()->where('id', $key)->orWhere('varchar_column', $key)->exists();
```

## También aplica a

- `Model::find($value)` donde la PK es UUID
- `whereIn('uuid_col', $mixedArray)` si el array puede tener no-UUIDs
- Cualquier bind de PDO contra columna UUID

## Alternativa: usar `try/catch` (menos limpia)

```php
try {
    return Model::where('id', $key)->exists();
} catch (QueryException $e) {
    // Fallback a búsqueda por otro campo
}
```

Pero es mejor no llegar a la excepción: la validación con regex es más barata.

## Caso real en este proyecto

`BackfillOrdersFromShipmentsCommand` recibe `order_reference` que puede ser:
- UUID (flujo real del checkout) → sí comparar contra `id`
- Numérico (Magento: `100000452`) → NO comparar contra `id`
- Alfanumérico (manual: `MR-161W94-261110-01`) → NO comparar contra `id`
