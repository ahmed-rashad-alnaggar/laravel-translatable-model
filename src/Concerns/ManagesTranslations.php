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
     * Retrieve the translation of a **concrete translatable attribute** for the given locale.
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

        $translation = $this->getTranslationWithResolvedKey($this->resolveTranslationKey($key), $locale, $fallbackStrategy);

        if (is_null($translation)) {
            return null;
        }

        if (! str_contains($key, '.')) {
            return $this->transformModelValue($key, $translation);
        }

        [$column, $path] = explode('.', $key, 2);

        $attribute = $this->getArrayAttributeByKey($column);

        Arr::set($attribute, $path, $this->decodeNestedTranslation($translation));

        $attribute = $this->transformModelValue($column, $this->castColumnNestingTranslatablesArrayValue($column, $attribute));

        return data_get($attribute, $path);
    }

    /**
     * Retrieve translations of a **concrete translatable attribute**, for the given `$locales`,
     * or across every locale the model has translations for.
     *
     * @param string $key
     * @param array<string>|null $locales
     * @param \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy|class-string<\Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy>|string|null $fallbackStrategy Fallback strategy to follow when the translation for a locale is missing
     * @return array<string, mixed>|null
     */
    public function getTranslations(string $key, ?array $locales = null, FallbackStrategy|string|null $fallbackStrategy = null): ?array
    {
        if (! $this->isTranslatableAttribute($key)) {
            return null;
        }

        if (is_null($locales)) {
            $this->loadAllTranslations();
        } else {
            foreach ($locales as $locale) {
                $this->loadTranslations($locale);
            }
        }

        $translations = [];

        $translationKey = $this->resolveTranslationKey($key);
        $locales ??= $this->getTranslationsState()->locales();
        $fallbackStrategy = FallbackStrategy::make($fallbackStrategy ?? $this->getDefaultTranslationsFallbackStrategy());

        if (str_contains($key, '.')) {
            [$column, $path] = explode('.', $key, 2);

            $attribute = $this->getArrayAttributeByKey($column);
        }

        foreach ($locales as $locale) {
            $translation = $this->getTranslationWithResolvedKey($translationKey, $locale, $fallbackStrategy);

            if (is_null($translation)) {
                $translations[$locale] = null;

                continue;
            }

            if (! str_contains($key, '.')) {
                $translations[$locale] = $this->transformModelValue($key, $translation);

                continue;
            }

            Arr::set($attribute, $path, $this->decodeNestedTranslation($translation));

            $castedAttribute = $this->transformModelValue($column, $this->castColumnNestingTranslatablesArrayValue($column, $attribute));

            $translations[$locale] = data_get($castedAttribute, $path);
        }

        return $translations;
    }

    /**
     * Retrieve the translation in its **stored representation form**
     * for a **concrete translatable attribute**, given its already-resolved translation key.
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
     * Set or add translation for a **concrete translatable attribute**.
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

        $translationKey = $this->resolveTranslationKey($key);
        $locale ??= app()->currentLocale();

        if (is_null($translation)) {
            $this->removeTranslationWithResolvedKey($translationKey, $locale);

            return $this;
        }

        $attributes = $this->attributes;

        TranslatableModel::withoutTranslations(function () use ($key, $translationKey, $translation, $locale): void {
            if (! str_contains($key, '.')) {
                $this->setAttribute($key, $translation);

                $this->setTranslationWithResolvedKey($translationKey, $this->getAttributeFromArray($key), $locale);
            } else {
                $keySegments = explode('.', $key);
                $column = array_shift($keySegments);

                $attribute = $this->forceMutateNestedTranslationWithinCastedNestingColumnValue($this->getAttributeValue($column), $keySegments, $translation);

                $this->setAttribute($column, $attribute);

                $translation = Arr::get($this->getArrayAttributeByKey($column), implode('.', $keySegments));

                $this->setTranslationWithResolvedKey($translationKey, $this->encodeNestedTranslation($translation), $locale);
            }
        });

        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Set or add translations for a **concrete translatable attribute**.
     *
     * @param string $key
     * @param array<string, mixed> $translations
     * @return static
     */
    public function setTranslations(string $key, array $translations): static
    {
        if (! $this->isTranslatableAttribute($key)) {
            return $this;
        }

        $translationKey = $this->resolveTranslationKey($key);

        $attributes = $this->attributes;

        TranslatableModel::withoutTranslations(function () use ($key, $translationKey, $translations): void {
            if (str_contains($key, '.')) {
                $keySegments = explode('.', $key);
                $column = array_shift($keySegments);
                $path = implode('.', $keySegments);

                $castedAttribute = $this->getAttributeValue($column);
            }

            foreach ($translations as $locale => $translation) {
                if (is_null($translation)) {
                    $this->removeTranslationWithResolvedKey($translationKey, $locale);

                    continue;
                }

                if (! str_contains($key, '.')) {
                    $this->setAttribute($key, $translation);

                    $this->setTranslationWithResolvedKey($translationKey, $this->getAttributeFromArray($key), $locale);

                    continue;
                } else {
                    $attribute = $this->forceMutateNestedTranslationWithinCastedNestingColumnValue($castedAttribute, $keySegments, $translation);

                    $this->setAttribute($column, $attribute);

                    $translation = Arr::get($this->getArrayAttributeByKey($column), $path);

                    $this->setTranslationWithResolvedKey($translationKey, $this->encodeNestedTranslation($translation), $locale);
                }
            }
        });


        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Recursively walk a translation key's dot-separated segments into a nested
     * array/object structure and write the given value at the leaf, resolving
     * each intermediate segment's current value on the way down and writing it
     * back on the way up via data_set() (which already covers plain properties
     * and array keys). If a segment turns out to be a readonly property,
     * data_set()'s failure is caught and a fresh instance of that
     * object is rebuilt via reflection instead, copying every other property
     * across unchanged and substituting the new value for this one.
     *
     * @param object|array $target
     * @param array $keySegments
     * @param mixed $translation
     * @return array|object
     */
    protected function forceMutateNestedTranslationWithinCastedNestingColumnValue(object|array $target, array $keySegments, mixed $translation): object|array
    {
        $keySegment = array_shift($keySegments);

        if (blank($keySegments)) {
            $nestedValue = $translation;
        } else {
            if (Arr::accessible($target)) {
                $nestedTarget = $target[$keySegment] ?? null;
            } else {
                $nestedTarget = $target->$keySegment;
            }

            $nestedValue = $this->forceMutateNestedTranslationWithinCastedNestingColumnValue($nestedTarget, $keySegments, $translation);
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
    }

    /**
     * Set or add translation in its **stored representation form**
     * for a **concrete translatable attribute**, given its already-resolved translation key.
     *
     * @param string $key
     * @param string|null $value
     * @param string $locale
     * @return void
     * @internal
     */
    protected function setTranslationWithResolvedKey(string $key, ?string $value, string $locale): void
    {
        if (! is_null($value)) {
            $this->getTranslationsState()->upsert($key, $value, $locale);
        } else {
            $this->removeTranslationWithResolvedKey($key, $locale);
        }
    }

    /**
     * Remove a **concrete translatable attribute** translation.
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

        $this->removeTranslationWithResolvedKey($key, $locale);

        return $this;
    }

    /**
     * Remove a **concrete translatable attribute** translation,
     * given its already-resolved translation key.
     *
     * @param string $key
     * @param string $locale
     * @return void
     * @internal
     */
    protected function removeTranslationWithResolvedKey(string $key, string $locale): void
    {
        $this->getTranslationsState()->delete($key, $locale);
    }

    /**
     * Remove the entire translations for the given key(s).
     *
     * @param array<string>|string $keys
     * @return static
     */
    public function removeTranslationsForKeys(array|string $keys): static
    {
        $this->removeTranslationsWithResolvedKeys(
            array_map($this->resolveTranslationKey(...), array_filter((array) $keys, $this->isTranslatableAttribute(...)))
        );

        return $this;
    }

    /**
     * Remove the entire translations for the given, already-resolved keys.
     *
     * @param array<string> $keys
     * @return void
     * @internal
     */
    protected function removeTranslationsWithResolvedKeys(array $keys): void
    {
        $this->getTranslationsState()->deleteKeys($keys);
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
     * Determine if the given **concrete translatable attribute** has a translation for the specified locale.
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
