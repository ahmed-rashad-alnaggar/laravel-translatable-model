<?php

namespace Alnaggar\TranslatableModel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed withoutTranslations(callable $callback)
 * @method static bool isTranslationsDisabled()
 * @method static string|null connection()
 * @method static \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy defaultFallbackStrategy()
 * @method static bool shouldFlushTranslationsOnSoftDelete()
 *
 * @see \Alnaggar\TranslatableModel\TranslatableModelManager
 */
class TranslatableModel extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return 'translatable-model';
    }
}
