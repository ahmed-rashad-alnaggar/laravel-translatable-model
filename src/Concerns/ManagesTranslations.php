<?php

namespace Alnaggar\TranslatableModel\Concerns;

use Alnaggar\TranslatableModel\Facades\TranslatableModel;
use Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy;
use Alnaggar\TranslatableModel\FallbackStrategies\NoFallbackStrategy;
use Illuminate\Support\Arr;

trait ManagesTranslations
{
    /**
     * Load and cache model translations for a specific locale.
     *
     * @param string $locale
     * @return static
     */
    public function loadTranslations(string $locale): static
    {
        if (
            isset($this->attributes[$this->getKeyName()])
            && ! $this->getTranslationsState()->isAllLoaded()
            && ! $this->getTranslationsState()->isLoaded($locale)
        ) {
            $this->getTranslationsState()->load($locale);
        }

        return $this;
    }

    /**
     * Load and cache model translations across all locales.
     *
     * @return static
     */
    public function loadAllTranslations(): static
    {
        if (
            isset($this->attributes[$this->getKeyName()])
            && ! $this->getTranslationsState()->isAllLoaded()
        ) {
            $this->getTranslationsState()->loadAll();
        }

        return $this;
    }

    /**
     * Retrieve the translation of a **listed translatable attribute** for the given locale.
     *
     * @param string $key
     * @param string|null $locale Translation locale; defaults to app locale.
     * @param \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy|class-string<\Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy>|string|null $fallbackStrategy Fallback strategy to follow when the translation for the given locale is missing
     * @return mixed
     */
    public function getTranslation(string $key, ?string $locale = null, FallbackStrategy|string|null $fallbackStrategy = null): mixed
    {
        if (! $this->isTranslatableAttribute($key)) {
            return null;
        }

        $locale ??= app()->currentLocale();
        $fallbackStrategy = FallbackStrategy::make($fallbackStrategy ?? $this->getDefaultTranslationsFallbackStrategy());

        $attributes = $this->attributes;

        // Before calling the methods that fetch the column value,
        // set the placeholder to null as those methods fall back to it,
        // while the purpose of this method is only to get the translation.

        if (! str_contains($key, '.')) {
            $this->attributes[$key] = null;

            $translation = $this->transformModelValue(
                $key,
                $this->getTranslatableColumnValue($key, $locale, $fallbackStrategy)
            );
        } else {
            TranslatableModel::withoutTranslations(function () use ($key): void {
                $this[str_replace('.', '->', $key)] = null;
            });

            [$column, $path] = explode('.', $key, 2);

            $attribute = $this->transformModelValue(
                $column,
                $this->getColumnNestingTranslatablesValue($column, $locale, $fallbackStrategy)
            );

            $translation = data_get($attribute, $path);
        }

        $this->attributes = $attributes;

        return $translation;
    }

    /**
     * Retrieve all translations of a **listed translatable attribute** across all locales.
     *
     * @param string $key
     * @param \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy|class-string<\Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy>|string|null $fallbackStrategy Fallback strategy to follow when the translation for a locale is missing
     * @return array|null
     */
    public function getTranslations(string $key, FallbackStrategy|string|null $fallbackStrategy = null): ?array
    {
        if (! $this->isTranslatableAttribute($key)) {
            return null;
        }

        $this->loadAllTranslations();

        $translations = [];

        $locales = $this->getTranslationsState()->locales();
        $fallbackStrategy = FallbackStrategy::make($fallbackStrategy ?? $this->getDefaultTranslationsFallbackStrategy());

        foreach ($locales as $locale) {
            $translations[$locale] = $this->getTranslation($key, $locale, $fallbackStrategy);
        }

        return $translations;
    }

    /**
     * Retrieve the translation of a **listed translatable attribute**, given its
     * already-resolved, identity-based translation key.
     *
     * @param string $key
     * @param string $locale
     * @param \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy $fallbackStrategy
     * @return string|null
     */
    protected function getTranslationWithResolvedKey(string $key, string $locale, FallbackStrategy $fallbackStrategy): ?string
    {
        $this->loadTranslations($locale);

        return $this->getTranslationsState()->get($key, $locale) ??
            $fallbackStrategy->apply($this, $key, $locale, function (string $fallbackLocale) use ($key): ?string {
                $this->loadTranslations($fallbackLocale);

                return $this->getTranslationsState()->get($key, $fallbackLocale);
            });
    }

    /**
     * Set or add translation for a **listed translatable attribute**.
     *
     * @param string $key
     * @param mixed $translation
     * @param string|null $locale Translation locale; defaults to app locale.
     * @return static
     */
    public function setTranslation(string $key, mixed $translation, ?string $locale = null): static
    {
        if (! $this->isTranslatableAttribute($key)) {
            return $this;
        }

        $locale ??= app()->currentLocale();

        $attributes = $this->attributes;

        if (! str_contains($key, '.')) {
            $this->setTranslatableColumn($key, $translation, $locale);
        } else {
            // Recursively walk a translation key's dot-separated segments into a nested
            // array/object structure and write the given value at the leaf, resolving
            // each intermediate segment's current value on the way down and writing it
            // back on the way up via data_set() (which already covers plain properties,
            // array keys, and magic __set). If a segment turns out to be a readonly
            // property, data_set()'s failure is caught and a fresh instance of that
            // object is rebuilt via reflection instead, copying every other property
            // across unchanged and substituting the new value for this one.
            $setNestedTranslation = static function (object|array $target, array $keySegments, mixed $translation) use (&$setNestedTranslation): object|array {
                $keySegment = array_shift($keySegments);

                if (blank($keySegments)) {
                    $nestedValue = $translation;
                } else {
                    if (Arr::accessible($target)) {
                        $nestedTarget = $target[$keySegment] ?? null;
                    } else {
                        $nestedTarget = $target->$keySegment;
                    }

                    $nestedValue = $setNestedTranslation($nestedTarget, $keySegments, $translation);
                }

                try {
                    data_set($target, $keySegment, $nestedValue);

                    return $target;
                } catch (\Error $error) {
                    if (! str_starts_with($error->getMessage(), 'Cannot modify readonly property')) {
                        throw $error;
                    }
                }

                $reflection = new \ReflectionClass($target);

                $mutated = $reflection->newInstanceWithoutConstructor();

                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic()) {
                        continue;
                    }

                    $propertyName = $property->getName();

                    if ($propertyName === $keySegment) {
                        $property->setValue($mutated, $nestedValue);

                        continue;
                    }

                    if ($property->isInitialized($target)) {
                        $property->setValue($mutated, $property->getValue($target));
                    }
                }

                return $mutated;
            };

            $keySegments = explode('.', $key);
            $column = array_shift($keySegments);

            $attribute = $setNestedTranslation($this->getAttributeValue($column), $keySegments, $translation);

            $this->setColumnNestingTranslatables($column, $attribute, $locale);
        }

        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Set or add translations for a **listed translatable attribute**.
     *
     * @param string $key
     * @param array<string, string|null> $translations
     * @return static
     */
    public function setTranslations(string $key, array $translations): static
    {
        if (! $this->isTranslatableAttribute($key)) {
            return $this;
        }

        foreach ($translations as $locale => $translation) {
            $this->setTranslation($key, $translation, $locale);
        }

        return $this;
    }

    /**
     * Set or add translation for a **listed translatable attribute**, given
     * its already-resolved, identity-based translation key.
     *
     * @param string $key
     * @param string|null $value
     * @param string $locale
     * @return static
     * @internal
     */
    protected function setTranslationWithResolvedKey(string $key, ?string $value, string $locale): static
    {
        if (! is_null($value)) {
            $this->getTranslationsState()->upsert($key, $value, $locale);
        } else {
            $this->removeTranslationWithResolvedKey($key, $locale);
        }

        return $this;
    }

    /**
     * Remove a **listed translatable attribute** translation.
     *
     * @param string $key
     * @param string|null $locale Translation locale; defaults to app locale.
     * @return static
     */
    public function removeTranslation(string $key, ?string $locale = null): static
    {
        if (! $this->isTranslatableAttribute($key)) {
            return $this;
        }

        $key = $this->resolveTranslationKey($key);
        $locale ??= app()->currentLocale();

        return $this->removeTranslationWithResolvedKey($key, $locale);
    }

    /**
     * Remove a **listed translatable attribute** translation, given its
     * already-resolved, identity-based translation key.
     *
     * @param string $key
     * @param string $locale
     * @return static
     * @internal
     */
    protected function removeTranslationWithResolvedKey(string $key, string $locale): static
    {
        $this->getTranslationsState()->delete($key, $locale);

        return $this;
    }

    /**
     * Remove the entire translations for the given key(s).
     *
     * @param array<string>|string $keys
     * @return static
     */
    public function removeTranslationsForKeys(array|string $keys): static
    {
        return $this->removeTranslationsWithResolvedKeys(
            array_map($this->resolveTranslationKey(...), array_filter((array) $keys, $this->isTranslatableAttribute(...)))
        );
    }

    /**
     * Remove the entire translations for the given, already-resolved, identity-based keys.
     *
     * @param array<string> $keys
     * @return static
     * @internal
     */
    protected function removeTranslationsWithResolvedKeys(array $keys): static
    {
        $this->getTranslationsState()->deleteKeys($keys);

        return $this;
    }

    /**
     * Remove the entire translations for the given locale(s).
     *
     * @param array<string>|string $locales
     * @return static
     */
    public function removeTranslationsForLocales(array|string $locales): static
    {
        $this->getTranslationsState()->deleteLocales($locales);

        return $this;
    }

    /**
     * Remove all translations for the model, across all locales.
     *
     * @return static
     */
    public function flushAllTranslations(): static
    {
        $this->getTranslationsState()->flushAll();

        return $this;
    }

    /**
     * Determine if the given **listed translatable attribute** has a translation for the specified locale.
     *
     * @param string $key
     * @param string|null $locale Translation locale; defaults to app locale.
     * @return bool
     */
    public function hasTranslation(string $key, ?string $locale = null): bool
    {
        return ! is_null($this->getTranslation($key, $locale, NoFallbackStrategy::class));
    }
}
