<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait HasTranslations
{
    /**
     * Cached dot-notation array of the translatable attributes.
     * 
     * @var array<string>
     * @internal
     */
    protected $cachedTranslatables;

    /**
     * Cached translations to avoid fetching them from the database on every retrieval call.
     * 
     * @var array<string, array<string, string>>
     * @internal
     */
    protected $cachedTranslations = [];

    /**
     * Cached translations to update when saving the model.
     * 
     * @var array<string, array<string, string>>
     * @internal
     */
    protected $cachedTranslationsToUpdate = [];

    /**
     * Cached translations to delete when saving the model.
     * 
     * @var array<string, array<string>>
     * @internal
     */
    protected $cachedTranslationsToDelete = [];

    /**
     * Boot the HasTranslations trait. 
     * 
     * @return void
     */
    public static function bootHasTranslations(): void
    {
        // Defer saving/deleting translations until the model is saved.
        static::saved(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model */ $model): void {
            $model->handleTranslationsToUpdate();
            $model->handleTranslationsToDelete();
        });

        // Flush all related translation when the model is deleted.
        static::deleted(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model */ $model): void {
            app(ModelTranslationsRepository::class)->flushModelTranslations($model->getMorphClass(), $model->getKey());
        });
    }

    /**
     * Upserts translations in/to the database.
     * 
     * @return void
     * @internal
     */
    protected function handleTranslationsToUpdate(): void
    {
        foreach ($this->cachedTranslationsToUpdate as $locale => $translations) {
            app(ModelTranslationsRepository::class)->upsertModelTranslations($translations, $this->getMorphClass(), $this->getKey(), $locale);
        }

        $this->cachedTranslations = array_replace_recursive($this->cachedTranslations, $this->cachedTranslationsToUpdate);

        // Clear the cache.
        $this->cachedTranslationsToUpdate = [];
    }

    /**
     * Removes cached to remove translations from the store.
     * 
     * @return void
     * @internal
     */
    protected function handleTranslationsToDelete(): void
    {
        foreach ($this->cachedTranslationsToDelete as $locale => $keys) {
            app(ModelTranslationsRepository::class)->removeModelTranslations($keys, $this->getMorphClass(), $this->getKey(), $locale);

            foreach ($keys as $key) {
                unset($this->cachedTranslations[$locale][$key]);
            }
        }

        // Clear the cache.
        $this->cachedTranslationsToDelete = [];
    }

    /**
     * {@inheritDoc}
     */
    public function getAttributeValue($key)
    {
        if ($key !== $this->getKeyName()) {
            if ($this->isTranslatableAttribute($key)) {
                return $this->getTranslatableAttributeValue($key, null, $this->defaultFallbackBehavior());
            }

            if ($this->isAttributeNestingTranslatableAttribute($key)) {
                return $this->getAttributeNestingTranslatableAttributeValue($key, null, $this->defaultFallbackBehavior());
            }
        }

        return parent::getAttributeValue($key);
    }

    /**
     * Retrieve the value of a translatable attribute.
     * 
     * @param string $key
     * @param string|null $locale Translation locale, fallback to app locale if `null`
     * @param string|bool|null $fallback Missing locale translation fallback behavior
     * - `string` (locale) => fallback to that locale
     * - `true`|`null` => fallback to app fallback locale
     * - `false' => do not fallback to any locale
     * @return mixed
     */
    public function getTranslation(string $key, ?string $locale = null, $fallback = null)
    {
        return $this->getTranslatableAttributeValue($key, $locale, $fallback);
    }

    /**
     * Retrieves the translation of a listed translatable attribute.
     * 
     * @param string $key
     * @param string|null $locale
     * @param string|bool|null $fallback
     * @return string|null
     */
    protected function getTranslatableAttributeValue(string $key, ?string $locale, $fallback): ?string
    {
        $locale = $locale ?? app()->currentLocale();

        if (! array_key_exists($locale, $this->cachedTranslations)) {
            $this->cachedTranslations[$locale] = app(ModelTranslationsRepository::class)->getModelTranslationsForLocale($this->getMorphClass(), $this->getKey(), $locale);
        }

        $translation = null;

        if (! in_array($key, $this->cachedTranslationsToDelete[$locale] ?? [])) {
            $translation = $this->cachedTranslationsToUpdate[$locale][$key]
                ?? $this->cachedTranslations[$locale][$key]
                ?? null;
        }

        if (is_null($translation)) {
            if ($fallback !== false) {
                $fallback = is_string($fallback) ? $fallback : app()->getFallbackLocale();

                if ($locale !== $fallback) {
                    $translation = $this->getTranslatableAttributeValue($key, $fallback, false);
                }
            }
        }

        return $translation;
    }

    /**
     * Retrieves the root attribute with all nested translatable values injected.
     * 
     * @param string $key
     * @param string|null $locale
     * @param string|bool|null $fallback
     * @return mixed
     */
    protected function getAttributeNestingTranslatableAttributeValue(string $key, ?string $locale, $fallback)
    {
        $attribute = parent::getAttributeValue($key);

        collect($this->translatables())
            ->filter(static function (string $translatableKey) use ($key): bool {
                return Str::startsWith($translatableKey, $key.'.');
            })
            ->each(function (string $translatableKey) use (&$attribute, $locale, $fallback): void {
                $translation = $this->getTranslatableAttributeValue($translatableKey, $locale, $fallback);
                $nestedKey = Str::after($translatableKey, '.');

                data_set($attribute, $nestedKey, $translation);
            });

        return $attribute;
    }

    /**
     * {@inheritDoc}
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();
        $hiddenAttributes = $this->getHidden();

        foreach ($this->translatables() as $key) {
            if (! in_array(strstr($key, '.', true) ?: $key, $hiddenAttributes)) {
                data_set($attributes, $key, $this->getTranslatableAttributeValue($key, null, $this->defaultFallbackBehavior()));
            }
        }

        return $attributes;
    }

    /**
     * {@inheritDoc}
     */
    public function setAttribute($key, $value)
    {
        $normalizedKey = str_replace('->', '.', $key);

        if ($this->isTranslatableAttribute($normalizedKey)) {
            return $this->setTranslatableAttributeValue($key, $value, null);
        }

        if ($this->isAttributeNestingTranslatableAttribute($normalizedKey)) {
            return $this->setAttributeNestingTranslatableAttributeValue($key, $value, null);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Set/Add a translation(s) for a translatable attribute.
     * 
     * @param string $key
     * @param array<string, string>|string $value
     * @param string|null $locale Translation locale, fallback to app locale if `null`
     * @return static
     */
    public function setTranslation(string $key, $value, ?string $locale = null)
    {
        return $this->setTranslatableAttributeValue($key, $value, $locale);
    }

    /**
     * Sets/Adds the translation(s) for a translatable attribute.
     * 
     * @param string $key
     * @param array<string, string>|string $value
     * @param string|null $locale
     * @return static
     */
    protected function setTranslatableAttributeValue(string $key, $value, ?string $locale)
    {
        $locale = $locale ?? app()->currentLocale();

        if (! is_array($value)) {
            $value = [$locale => $value];
        }

        foreach ($value as $translationLocale => $translation) {
            if (! is_null($translation)) {
                $this->cachedTranslationsToUpdate[$translationLocale][$key] = $translation;

                if (isset($this->cachedTranslatables)) {
                    if (! in_array($key, $this->cachedTranslatables)) {
                        $this->cachedTranslatables[] = $key;
                    }
                }

                if (array_key_exists($locale, $this->cachedTranslationsToDelete)) {
                    $translationKeyIndex = array_search($key, $this->cachedTranslationsToDelete[$locale]);

                    if ($translationKeyIndex !== false) {
                        unset($this->cachedTranslationsToDelete[$locale][$translationKeyIndex]);
                    }
                }
            } else {
                $this->removeTranslation($key, $translationLocale);
            }
        }

        // Setting the translatable attribute to null as it should be represented in the database.
        if (! Str::contains($key, '.')) {
            parent::setAttribute($key, null);
        }

        return $this;
    }

    /**
     * Sets an attribute while handling its nested translatable attributes.
     * 
     * @param string $key
     * @param mixed $value
     * @param string|null $locale
     * @return static
     */
    protected function setAttributeNestingTranslatableAttributeValue(string $key, $value, ?string $locale)
    {
        collect($this->translatables())
            ->filter(static function (string $translatableKey) use ($key): bool {
                return Str::startsWith($translatableKey, $key.'.');
            })
            ->each(function (string $translatableKey) use (&$value, $locale): void {
                $nestedKey = Str::after($translatableKey, '.');
                $translation = data_get($value, $nestedKey);
                $this->setTranslatableAttributeValue($translatableKey, $translation, $locale);

                // Setting the nested translatable attribute to null as it should be represented in the database.
                data_set($value, $nestedKey, null);
            });

        return parent::setAttribute($key, $value);
    }

    /**
     * Remove a (?nested) translatable attribute translation.
     * 
     * @param string $key
     * @param string|null $locale Translation locale, fallback to app locale if `null`
     */
    public function removeTranslation(string $key, ?string $locale = null)
    {
        $locale = $locale ?? app()->currentLocale();

        $this->cachedTranslationsToDelete[$locale][] = $key;

        unset($this->cachedTranslationsToUpdate[$locale][$key]);

        return $this;
    }

    /**
     * Remove all translations for the given `$locale` or for all locales if `$locale` is `null`.
     *
     * @param string|null $locale
     * @return static
     */
    public function flushTranslations(?string $locale)
    {
        if (is_null($locale)) {
            $translations = array_replace_recursive(
                $this->cachedTranslations,
                $this->cachedTranslationsToUpdate,
                app(ModelTranslationsRepository::class)->getModelTranslations($this->getMorphClass(), $this->getKey())
            );
        } else {
            $translations = [$locale => array_replace_recursive(
                $this->cachedTranslations[$locale] ?? [],
                $this->cachedTranslationsToUpdate[$locale] ?? [],
                app(ModelTranslationsRepository::class)->getModelTranslationsForLocale($this->getMorphClass(), $this->getKey(), $locale)
            )];
        }

        foreach ($translations as $targetLocale => $targetLocaleTranslations) {
            foreach (array_keys($targetLocaleTranslations) as $key) {
                $this->removeTranslation($key, $targetLocale);
            }
        }

        return $this;
    }

    /**
     * Determine if the given **listed translatable attribute** has a translation for the specified locale.
     * 
     * @param string $key
     * @param string|null $locale Translation locale, fallback to app locale if `null`
     * @return bool
     */
    public function hasTranslation(string $key, ?string $locale = null): bool
    {
        return ! is_null($this->getTranslatableAttributeValue($key, $locale, false));
    }

    /**
     * Check if the attribute is translatable.
     * 
     * @param string $key
     * @return bool
     */
    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translatables());
    }

    /**
     * Checks if the attribute contains any translatable sub-attributes.

     * @param string $key
     * @return bool
     */
    public function isAttributeNestingTranslatableAttribute(string $key): bool
    {
        foreach ($this->translatables() as $translatable) {
            if (Str::startsWith($translatable, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the default missing translation fallback behavior.
     * 
     * @return string|bool|null
     */
    protected function defaultFallbackBehavior()
    {
        return property_exists($this, 'defaultFallbackBehavior')
            ? $this->defaultFallbackBehavior
            : config('translatable-model.fallback_behavior');
    }

    /**
     * A dot notation array of the translatable attributes.
     * 
     * @return array
     */
    public function translatables(): array
    {
        if (isset($this->cachedTranslatables)) {
            return $this->cachedTranslatables;
        }

        return $this->cachedTranslatables = property_exists($this, 'translatables')
            ? $this->translatables
            : array_keys(array_merge(
                Arr::collapse($this->cachedTranslationsToUpdate),
                Arr::collapse(app(ModelTranslationsRepository::class)->getModelTranslations($this->getMorphClass(), $this->getKey()))
            ));
    }
}
