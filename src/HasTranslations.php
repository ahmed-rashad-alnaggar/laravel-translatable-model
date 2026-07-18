<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait HasTranslations
{
    /**
     * Cached dot-notated array of the translatable attributes.
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
     * Cached translations-to-update when saving the model.
     * 
     * @var array<string, array<string, string>>
     * @internal
     */
    protected $cachedTranslationsToUpdate = [];

    /**
     * Cached translations-to-delete when saving the model.
     * 
     * @var array<string, array<string>>
     * @internal
     */
    protected $cachedTranslationsToDelete = [];

    /**
     * Cached translations-to-flush when saving the model.
     * 
     * @var array<string>
     * @internal
     */
    protected $cachedTranslationsToFlush = [];

    /**
     * Cached locales-to-flush when saving the model.
     * 
     * @var array<string>
     * @internal
     */
    protected $cachedLocalesToFlush = [];

    /**
     * Boot the HasTranslations trait. 
     * 
     * @return void
     */
    public static function bootHasTranslations(): void
    {
        // Defer saving/deleting translations until the model is saved.
        static::saved(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model */ $model): void {
            $model->handleLocalesToFlush();
            $model->handleTranslationsToFlush();
            $model->handleTranslationsToUpdate();
            $model->handleTranslationsToDelete();
        });

        // Flush all related translations when the model is deleted, with respect to soft-deletes.
        static::deleted(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model */ $model): void {
            if (
                method_exists($model, 'trashed') // Model uses the SoftDeletes trait
                && $model->exists // true => Model is soft-deleted, false => Model is force-deleted
                && ! $model->shouldFlushTranslationsOnSoftDelete()
            ) {
                return;
            }

            $model->translationsRepository()->flushAllModelTranslations($model->getMorphClass(), $model->getKey());
        });
    }

    /**
     * Upsert cached translations-to-update into the database.
     * 
     * @return void
     * @internal
     */
    protected function handleTranslationsToUpdate(): void
    {
        foreach ($this->cachedTranslationsToUpdate as $locale => $translations) {
            if (filled($translations)) {
                $this->translationsRepository()->upsertModelTranslationsForLocale($translations, $this->getMorphClass(), $this->getKey(), $locale);
            }
        }

        $this->cachedTranslations = array_replace_recursive($this->cachedTranslations, $this->cachedTranslationsToUpdate);

        // Clear the cache.
        $this->cachedTranslationsToUpdate = [];
    }

    /**
     * Delete cached translations-to-delete from the database.
     * 
     * @return void
     * @internal
     */
    protected function handleTranslationsToDelete(): void
    {
        foreach ($this->cachedTranslationsToDelete as $locale => $keys) {
            if (filled($keys)) {
                $this->translationsRepository()->deleteModelTranslationsForLocale($keys, $this->getMorphClass(), $this->getKey(), $locale);

                foreach ($keys as $key) {
                    unset($this->cachedTranslations[$locale][$key]);
                }
            }
        }

        $this->cachedTranslationsToDelete = [];
    }

    /**
     * Delete cached translations-to-flush from the database across all locales.
     * 
     * @return void
     * @internal
     */
    protected function handleTranslationsToFlush(): void
    {
        if (filled($this->cachedTranslationsToFlush)) {
            $this->translationsRepository()->flushModelTranslations($this->cachedTranslationsToFlush, $this->getMorphClass(), $this->getKey());

            $flippedKeys = array_flip($this->cachedTranslationsToFlush);

            foreach ($this->cachedTranslations as $locale => $translations) {
                $this->cachedTranslations[$locale] = array_diff_key($translations, $flippedKeys);
            }

            $this->cachedTranslationsToFlush = [];
        }
    }

    /**
     * Delete cached locales-to-flush from the database, or all translations
     * entirely if a `null` locale is queued.
     * 
     * @return void
     * @internal
     */
    protected function handleLocalesToFlush(): void
    {
        foreach ($this->cachedLocalesToFlush as $locale) {
            if (blank($locale)) {
                $this->translationsRepository()->flushAllModelTranslations($this->getMorphClass(), $this->getKey());
                $this->cachedTranslations = [];

                break;
            } else {
                $this->translationsRepository()->deleteAllModelTranslationsForLocale($this->getMorphClass(), $this->getKey(), $locale);
                unset($this->cachedTranslations[$locale]);
            }
        }

        $this->cachedLocalesToFlush = [];
    }

    /**
     * {@inheritDoc}
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return (new TranslatableQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        ))->setTranslatableModel($this);
    }

    /**
     * Resolve the model translations repository instance.
     * 
     * @return \Alnaggar\TranslatableModel\ModelTranslationsRepository
     */
    protected function translationsRepository(): ModelTranslationsRepository
    {
        return app(ModelTranslationsRepository::class);
    }

    /**
     * Load and cache model translations for a specific locale.
     * 
     * @param string $locale
     * @return void
     */
    public function loadTranslations(string $locale)
    {
        $this->cachedTranslations[$locale] = $this->translationsRepository()->getModelTranslationsForLocale($this->getMorphClass(), $this->getKey(), $locale);
    }

    /**
     * Load and cache model translations across all locales.
     * 
     * @return void
     */
    public function loadAllTranslations()
    {
        $this->cachedTranslations = $this->translationsRepository()->getModelTranslations($this->getMorphClass(), $this->getKey());
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

            if (
                $this->isAttributeNestingTranslatableAttribute($key)
                // Laravel doesn't support resolving nested attributes
                // via a dot-notated string key (e.g. $model['address.city']).
                && ! Str::contains($key, '.')
            ) {
                return $this->getAttributeNestingTranslatableAttributeValue($key, null, $this->defaultFallbackBehavior());
            }
        }

        return parent::getAttributeValue($key);
    }

    /**
     * Retrieve the value of a **listed translatable attribute**.
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
     * Retrieve the translation of a **listed translatable attribute**.
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
            $this->loadTranslations($locale);
        }

        $translation = null;

        if (
            array_key_exists($key, $this->cachedTranslationsToUpdate[$locale] ?? [])
            || (! in_array($key, $this->cachedTranslationsToDelete[$locale] ?? [])
                && ! in_array($key, $this->cachedTranslationsToFlush)
                && ! in_array($locale, $this->cachedLocalesToFlush))
        ) {
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
     * Retrieve the nesting attribute with all its nested translatable values injected.
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
            return $this->setTranslatableAttributeValue($normalizedKey, $value, null);
        }

        if (
            $this->isAttributeNestingTranslatableAttribute($normalizedKey)
            // Laravel doesn't support setting nested attributes
            // via a dot-notated string key (e.g. $model['address.city'] = $value).
            && ! Str::contains($key, '.')
        ) {
            return $this->setAttributeNestingTranslatableAttributeValue($normalizedKey, $value, null);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Set or add translation(s) for a **listed translatable attribute**.
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
     * Set or add translation(s) for a **listed translatable attribute**.
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

                if (array_key_exists($translationLocale, $this->cachedTranslationsToDelete)) {
                    $translationKeyIndex = array_search($key, $this->cachedTranslationsToDelete[$translationLocale]);

                    if ($translationKeyIndex !== false) {
                        unset($this->cachedTranslationsToDelete[$translationLocale][$translationKeyIndex]);
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
     * Set a nesting attribute while handling its nested translatable attributes.
     * 
     * @param string $key
     * @param mixed $value
     * @param string|null $locale
     * @return static
     */
    protected function setAttributeNestingTranslatableAttributeValue(string $key, $value, ?string $locale)
    {
        $toFlushTranslations = [];

        collect($this->translatables())
            ->filter(static function (string $translatableKey) use ($key): bool {
                return Str::startsWith($translatableKey, $key.'.');
            })
            ->each(function (string $translatableKey) use ($key, &$value, $locale, &$toFlushTranslations): void {
                // Unlike `getAttributeNestingTranslatableAttributeValue()` method, we strip the full `$key.` prefix here
                // instead of stopping at the first dot, since $key may itself be
                // dot-notated from a "root->nested" attribute rather than a single segment.
                $nestedKey = Str::after($translatableKey, $key.'.');

                if (Arr::has($value, $nestedKey)) {
                    $translation = data_get($value, $nestedKey);
                    $this->setTranslatableAttributeValue($translatableKey, $translation, $locale);

                    // Setting the nested translatable attribute to null as it should be represented in the database.
                    data_set($value, $nestedKey, null);
                } else {
                    $toFlushTranslations[] = $translatableKey;
                }
            });

        // If a previously tracked nested translatable key is completely missing from the 
        // incoming payload structure, it means the user purposefully removed that entire 
        // structural block from the nesting attribute. Therefore, we must clean up and 
        // delete its existing translations across all locales to keep the data consistent.
        if (filled($toFlushTranslations)) {
            $this->cachedTranslationsToFlush = array_unique(array_merge($this->cachedTranslationsToFlush, $toFlushTranslations));

            $flippedKeys = array_flip($toFlushTranslations);

            foreach ($this->cachedTranslationsToUpdate as $translationsLocale => $translations) {
                $this->cachedTranslationsToUpdate[$translationsLocale] = array_diff_key($translations, $flippedKeys);
            }

            foreach ($this->cachedTranslationsToDelete as $translationsLocale => $keys) {
                $this->cachedTranslationsToDelete[$translationsLocale] = array_diff($keys, $toFlushTranslations);
            }
        }

        return parent::setAttribute(str_replace('.', '->', $key), $value);
    }

    /**
     * Remove a **listed translatable attribute** translation.
     * 
     * @param string $key
     * @param string|null $locale Translation locale, fallback to app locale if `null`
     * @return static
     */
    public function removeTranslation(string $key, ?string $locale = null)
    {
        $locale = $locale ?? app()->currentLocale();

        if (! in_array($key, $this->cachedTranslationsToDelete[$locale] ?? [])) {
            $this->cachedTranslationsToDelete[$locale][] = $key;
        }

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
        if (! in_array($locale, $this->cachedLocalesToFlush)) {
            $this->cachedLocalesToFlush[] = $locale;
        }

        if (blank($locale)) {
            $this->cachedTranslationsToUpdate = [];
            $this->cachedTranslationsToDelete = [];
            $this->cachedTranslationsToFlush = [];
        } else {
            unset($this->cachedTranslationsToUpdate[$locale]);
            unset($this->cachedTranslationsToDelete[$locale]);
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
     * Check if the attribute contains any translatable nested attributes.
     * 
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
     * Determine if translations should be flushed when the model is soft-deleted.
     * 
     * @return bool
     */
    protected function shouldFlushTranslationsOnSoftDelete(): bool
    {
        return config('translatable-model.flush_translations_on_soft_delete', false);
    }

    /**
     * Get the default missing translation fallback behavior.
     * 
     * @return string|bool|null
     */
    protected function defaultFallbackBehavior()
    {
        static $defaultFallbackBehavior = property_exists($this, 'defaultFallbackBehavior')
            ? $this->defaultFallbackBehavior
            : config('translatable-model.fallback_behavior');

        return $defaultFallbackBehavior;
    }

    /**
     * A dot-notated array of the translatable attributes.
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
                array_flip($this->translationsRepository()->getModelTranslatableAttributes($this->getMorphClass(), $this->getKey()))
            ));
    }
}
