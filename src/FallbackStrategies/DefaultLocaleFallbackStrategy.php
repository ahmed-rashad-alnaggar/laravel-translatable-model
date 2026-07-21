<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

class DefaultLocaleFallbackStrategy extends FallbackStrategy
{
    /**
     * {@inheritDoc}
     */
    protected function fallbackLocales(Model $model, string $key, string $locale): array
    {
        return [app()->getFallbackLocale()];
    }
}
