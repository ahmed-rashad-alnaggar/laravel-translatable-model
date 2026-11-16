<?php

namespace Alnaggar\Laravel\TranslatableModel\Concerns;

use Alnaggar\Laravel\TranslatableModel\Facades\TranslatableModel;
use Alnaggar\Laravel\TranslatableModel\FallbackStrategies\FallbackStrategy;

trait HasDefaultFallbackStrategy
{
    /**
     * Get the default missing translation fallback strategy.
     *
     * @return \Alnaggar\Laravel\TranslatableModel\FallbackStrategies\FallbackStrategy
     */
    protected function getDefaultTranslationsFallbackStrategy(): FallbackStrategy
    {
        return TranslatableModel::defaultFallbackStrategy();
    }
}
