<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Settings;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lee la simbología de la etiqueta de la configuración versionada de `logistics/settings` (clave
 * `labels.symbology`, valor `{"symbology": "qr|barcode|both"}`) a través del QueryBus. Si todavía no hay
 * versión publicada para la fecha consultada, cae al valor por defecto de la config del package
 * (`config/logistics-labels.php`), de modo que la etiqueta siempre tiene una simbología con qué imprimirse
 * (docs/architecture.md, principio 5).
 *
 * Nota de contextos: labels lee el valor vigente de settings vía su API de lectura pública (QueryBus +
 * GetSettingQuery), no contra su persistencia; la referencia entre contextos es por su API, no por objeto
 * de dominio (mismo patrón que `capacity`).
 */
final readonly class SettingsLabelSymbologyProvider implements LabelSymbologyProvider
{
    /** Clave namespaced de la simbología de etiqueta en settings. */
    public const string SETTING_KEY = 'labels.symbology';

    /** Campo dentro del valor de settings que guarda la simbología. */
    public const string SETTING_FIELD = 'symbology';

    public function __construct(
        private QueryBus $queryBus,
        private Repository $config,
    ) {}

    public function current(DateTimeImmutable $at): LabelSymbology
    {
        /** @var mixed $value */
        $value = $this->queryBus->ask(new GetSettingQuery(self::SETTING_KEY, $at->format(DATE_ATOM)));

        // Hay versión publicada en settings: manda su valor.
        if (is_array($value) && array_key_exists(self::SETTING_FIELD, $value)) {
            return LabelSymbology::fromValue($value[self::SETTING_FIELD]);
        }

        // Sin versión en settings: cae al valor por defecto de la config del package.
        return LabelSymbology::fromValue($this->config->get('logistics-labels.symbology'));
    }
}
