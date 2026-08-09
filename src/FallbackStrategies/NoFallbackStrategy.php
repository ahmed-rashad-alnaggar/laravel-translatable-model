<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

class NoFallbackStrategy extends FallbackStrategy
{
    /**
     * {@inheritDoc}
     */
    protected function fallbackLocales(Model $model, string $key, string $requestedLocale): array
    {
        return [];
    }
}
