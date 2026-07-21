<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

class DedicatedLocaleFallbackStrategy extends FallbackStrategy
{
    /**
     * The locale to fallback to.
     *
     * @var string
     */
    protected $locale;

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
    protected function fallbackLocales(Model $model, string $key, string $locale): array
    {
        return [$this->locale];
    }
}
