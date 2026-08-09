<?php

namespace Alnaggar\TranslatableModel\FallbackStrategies;

use Illuminate\Database\Eloquent\Model;

abstract class FallbackStrategy
{
    /**
     * Resolve a fallback strategy from a strategy instance, a class-string, or a `"ClassName:arg1,arg2"` string.
     *
     * @template TStrategy of \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy
     *
     * @param TStrategy|class-string<TStrategy>|string $strategy
     * @throws \InvalidArgumentException
     * @return ($strategy is class-string<TStrategy> ? TStrategy : ($strategy is \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy ? TStrategy : \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy))
     */
    public static function make(FallbackStrategy|string $strategy)
    {
        if ($strategy instanceof static) {
            return $strategy;
        }

        $arguments = [];

        if (is_string($strategy) && strpos($strategy, ':') !== false) {
            $segments = explode(':', $strategy, 2);

            $strategy = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (! is_subclass_of($strategy, static::class)) {
            throw new \InvalidArgumentException("Invalid fallback strategy [{$strategy}] given.");
        }

        return new $strategy(...$arguments);
    }

    /**
     * Resolve a translation by walking this strategy's fallback locales, in order,
     * until `$lookup` finds one or the fallback locales are exhausted.
     *
     * @param \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model
     * @param string $key
     * @param string $requestedLocale The originally requested locale.
     * @param \Closure(string): (string|null) $lookup Attempts to resolve the translation for a single locale.
     * @return string|null
     */
    public function apply(Model $model, string $key, string $requestedLocale, callable $lookup): ?string
    {
        $attempted = [$requestedLocale => true];

        foreach ($this->fallbackLocales($model, $key, $requestedLocale) as $locale) {
            if (isset($attempted[$locale])) {
                continue;
            }

            $attempted[$locale] = true;
            $translation = $lookup($locale);

            if (! is_null($translation)) {
                return $translation;
            }
        }

        return $this->missing($model, $key, $requestedLocale);
    }

    /**
     * The locales this strategy would try, in order.
     *
     * @param \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model
     * @param string $key
     * @param string $requestedLocale
     * @return array<string>
     */
    abstract protected function fallbackLocales(Model $model, string $key, string $requestedLocale): array;

    /**
     * Value to use when every fallback locale is exhausted and none had a translation.
     *
     * @param \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model
     * @param string $key
     * @param string $requestedLocale
     * @return string|null
     */
    protected function missing(Model $model, string $key, string $requestedLocale): ?string
    {
        return null;
    }
}
