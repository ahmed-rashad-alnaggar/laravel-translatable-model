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
     * The translations state for this model instance.
     * 
     * @var \Alnaggar\TranslatableModel\ModelTranslationsState
     */
    protected $translationsState;

    /**
     * Initialize the HasTranslations trait. 
     * 
     * @return void
     */
    public function initializeHasTranslations(): void
    {
        $this->translationsState = new ModelTranslationsState($this);
    }

    /**
     * Boot the HasTranslations trait. 
     * 
     * @return void
     */
    public static function bootHasTranslations(): void
    {
        // Defer saving/deleting translations until the model is saved.
        static::saved(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model */ $model): void {
            $model->getTranslationsState()->commit();
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

            $model->getTranslationsState()->flushAll()->commit();
        });
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
     * Get the translations state for this model instance.
     *
     * @return \Alnaggar\TranslatableModel\ModelTranslationsState
     */
    public function getTranslationsState(): ModelTranslationsState
    {
        return $this->translationsState;
    }

    /**
     * Load and cache model translations for a specific locale.
     * 
     * @param string $locale
     * @return static
     */
    public function loadTranslations(string $locale)
    {
        if (! $this->getTranslationsState()->isLoaded($locale)) {
            $this->getTranslationsState()->load($locale);
        }

        return $this;
    }

    /**
     * Load and cache model translations across all locales.
     * 
     * @return static
     */
    public function loadAllTranslations()
    {
        if (! $this->getTranslationsState()->isAllLoaded()) {
            $this->getTranslationsState()->loadAll();
        }

        return $this;
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

        $this->loadTranslations($locale);

        $translation = $this->getTranslationsState()->get($key, $locale);

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
                $this->getTranslationsState()->upsert($key, $translation, $translationLocale);
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
        $toDeleteKeys = [];

        collect($this->translatables())
            ->filter(static function (string $translatableKey) use ($key): bool {
                return Str::startsWith($translatableKey, $key.'.');
            })
            ->each(function (string $translatableKey) use ($key, &$value, $locale, &$toDeleteKeys): void {
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
                    $toDeleteKeys[] = $translatableKey;
                }
            });

        // If a previously tracked nested translatable key is completely missing from the 
        // incoming payload structure, it means the user purposefully removed that entire 
        // structural block from the nesting attribute. Therefore, we must clean up and 
        // delete its existing translations across all locales to keep the data consistent.
        $this->removeTranslationsForKeys($toDeleteKeys);

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

        $this->getTranslationsState()->delete($key, $locale);

        return $this;
    }

    /**
     * Remove the entire translations for the given key(s).
     *
     * @param array<string>|string $keys
     * @return static
     */
    public function removeTranslationsForKeys($keys)
    {
        if (filled($keys)) {
            $this->getTranslationsState()->deleteKeys($keys);
        }

        return $this;
    }

    /**
     * Remove the entire translations for the given locale(s).
     *
     * @param array<string>|string $locales
     * @return static
     */
    public function removeTranslationsForLocales($locales)
    {
        if (filled($locales)) {
            $this->getTranslationsState()->deleteLocales($locales);
        }

        return $this;
    }

    /**
     * Remove all translations for the model, across all locale.
     *
     * @return static
     */
    public function flushAllTranslations()
    {
        $this->getTranslationsState()->flushAll();

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
     * @return array<string>
     */
    public function translatables(): array
    {
        if (isset($this->cachedTranslatables)) {
            return $this->cachedTranslatables;
        }

        return $this->cachedTranslatables = property_exists($this, 'translatables')
            ? $this->translatables
            : array_keys(array_merge(
                Arr::collapse($this->getTranslationsState()->queuedUpserts()),
                array_flip($this->discoverTranslatables())
            ));
    }

    /**
     * Discover translatable attribute keys from existing translations in the database.
     *
     * @return array<string>
     */
    protected function discoverTranslatables(): array
    {
        return app(ModelTranslationsRepository::class)->getModelKeys($this->getMorphClass(), $this->getKey());
    }
}
