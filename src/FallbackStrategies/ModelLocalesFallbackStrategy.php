<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

class ModelLocalesFallbackStrategy extends FallbackStrategy
{
    /**
     * Cached, ordered list of locales to cascade through.
     *
     * @var array<string>
     */
    protected array $locales;

    /**
     * {@inheritDoc}
     */
    protected function fallbackLocales(Model $model, string $key, string $requestedLocale): array
    {
        if (isset($this->locales)) {
            return $this->locales;
        }

        return $this->locales = $model->loadAllTranslations()->getTranslationsState()->locales();
    }
}
