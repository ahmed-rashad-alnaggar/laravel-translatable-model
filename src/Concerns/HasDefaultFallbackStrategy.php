<?php

namespace Alnaggar\TranslatableModel\Concerns;

use Alnaggar\TranslatableModel\Facades\TranslatableModel;
use Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy;

trait HasDefaultFallbackStrategy
{
    /**
     * Get the default missing translation fallback strategy.
     *
     * @return \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy
     */
    protected function getDefaultTranslationsFallbackStrategy(): FallbackStrategy
    {
        return TranslatableModel::defaultFallbackStrategy();
    }
}
