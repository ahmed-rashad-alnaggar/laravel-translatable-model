<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

class DedicatedLocaleFallbackStrategy extends FallbackStrategy
{
    /**
     * The locale to fall back to.
     *
     * @var string
     */
    protected string $locale;

    /**
     * Create a new instance.
     *
     * @param string $locale
     * @return void
     */
    public function __construct(string $locale)
    {
        $this->locale = $locale;
    }

    /**
     * {@inheritDoc}
     */
    protected function fallbackLocales(Model $model, string $key, string $requestedLocale): array
    {
        return [$this->locale];
    }
}
