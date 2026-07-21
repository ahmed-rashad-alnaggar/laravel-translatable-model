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
    protected $locales;

    /**
     * {@inheritDoc}
     */
    protected function fallbackLocales(Model $model, string $key, string $locale): array
    {
        if (isset($this->locales)) {
            return $this->locales;
        }

        return $this->locales = array_unique(array_merge(
            [app()->getFallbackLocale()],
            $model->loadAllTranslations()->getTranslationsState()->locales()
        ));
    }
}
