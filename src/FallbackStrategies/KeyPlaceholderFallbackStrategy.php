<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

class KeyPlaceholderFallbackStrategy extends FallbackStrategy
{
    /**
     * {@inheritDoc}
     */
    protected function fallbackLocales(Model $model, string $key, string $locale): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function missing(Model $model, string $key, string $requestedLocale): ?string
    {
        return "{$requestedLocale}.{$key}";
    }
}
