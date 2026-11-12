<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Request;

/**
 * Reglas de validación compartidas para las medidas físicas (peso kg, L/A/H cm) que reciben los
 * endpoints de integraciones. Los topes replican los del VO PhysicalSpec del dominio (que vuelve a
 * validar); aquí se rechaza la forma con 422 antes de despachar el comando.
 */
final class PhysicalSpecRules
{
    /** Tope de peso (kg) = PhysicalSpec::MAX_WEIGHT_KG. */
    public const MAX_WEIGHT_KG = 20000;

    /** Tope por lado (cm) = PhysicalSpec::MAX_DIMENSION_CM. */
    public const MAX_DIMENSION_CM = 2000;

    /** Máximo de ítems por lote. */
    public const MAX_BATCH_ITEMS = 500;

    /**
     * Reglas de los cuatro campos bajo un prefijo ('' para el cuerpo plano, 'items.*.' para el lote).
     * Al menos uno de los cuatro debe venir con valor: un cuerpo vacío es casi siempre un error del
     * integrador (para vaciar la ficha se usa el admin).
     *
     * @return array<string, list<string>>
     */
    public static function for(string $prefix = ''): array
    {
        $fields = ['weight_kg', 'length_cm', 'width_cm', 'height_cm'];
        $rules = [];

        foreach ($fields as $field) {
            $others = implode(',', array_map(
                static fn (string $other): string => $prefix.$other,
                array_values(array_diff($fields, [$field])),
            ));
            $max = $field === 'weight_kg' ? self::MAX_WEIGHT_KG : self::MAX_DIMENSION_CM;

            $rules[$prefix.$field] = [
                'nullable',
                'numeric',
                'gt:0',
                'max:'.$max,
                'required_without_all:'.$others,
            ];
        }

        return $rules;
    }

    /**
     * Convierte un valor validado (int, float o string numérico) a float; null si no viene.
     */
    public static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
