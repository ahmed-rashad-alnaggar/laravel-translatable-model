<?php

namespace Alnaggar\TranslatableModel\Concerns;

use Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy;
use Alnaggar\TranslatableModel\FallbackStrategies\NoFallbackStrategy;

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
     * @return string|null
     */
    public function getTranslation(string $key, ?string $locale = null, FallbackStrategy|string|null $fallbackStrategy = null): ?string
    {
        if (! $this->isTranslatableAttribute($key)) {
            return null;
        }

        $key = $this->resolveTranslationKey($key);
        $locale ??= app()->currentLocale();
        $fallbackStrategy = FallbackStrategy::make($fallbackStrategy ?? $this->getDefaultTranslationsFallbackStrategy());

        return $this->getTranslationWithResolvedKey($key, $locale, $fallbackStrategy);
    }

    /**
     * Retrieve all translations of a **concrete translatable attribute** across all locales.
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
        $key = $this->resolveTranslationKey($key);
        $locales = $this->getTranslationsState()->locales();
        $fallbackStrategy = FallbackStrategy::make($fallbackStrategy ?? $this->getDefaultTranslationsFallbackStrategy());

        foreach ($locales as $locale) {
            $translations[$locale] = $this->getTranslationWithResolvedKey($key, $locale, $fallbackStrategy);
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
     * @param string|null $value
     * @param string|null $locale Translation locale; defaults to app locale.
     * @return static
     */
    public function setTranslation(string $key, ?string $value, ?string $locale = null): static
    {
        if (! $this->isTranslatableAttribute($key)) {
            return $this;
        }

        $key = $this->resolveTranslationKey($key);
        $locale ??= app()->currentLocale();

        $this->setTranslationWithResolvedKey($key, $value, $locale);

        return $this;
    }

    /**
     * Set or add translations for a **concrete translatable attribute**.
     *
     * @param string $key
     * @param array<string, string|null> $values
     * @return static
     */
    public function setTranslations(string $key, array $values): static
    {
        if (! $this->isTranslatableAttribute($key)) {
            return $this;
        }

        $key = $this->resolveTranslationKey($key);

        foreach ($values as $locale => $translation) {
            $this->setTranslationWithResolvedKey($key, $translation, $locale);
        }

        return $this;
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
