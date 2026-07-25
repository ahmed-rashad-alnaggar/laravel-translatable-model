<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Database\Eloquent\Model;

class ModelTranslationsState
{
    /**
     * The owning model instance.
     * 
     * @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations
     */
    protected $model;

    /**
     * Cached translations, keyed by locale then attribute key.
     *
     * @var array<string, array<string, string>>
     */
    protected $translations = [];

    /**
     * Translations queued for upsert on commit, keyed by locale then attribute key.
     *
     * @var array<string, array<string, string>>
     */
    protected $toUpsert = [];

    /**
     * Attribute keys queued for deletion on commit, keyed by locale then attribute key (a membership set).
     *
     * @var array<string, array<string, true>>
     */
    protected $toDelete = [];

    /**
     * Attribute keys queued to be deleted entirely on commit (a membership set).
     *
     * @var array<string, true>
     */
    protected $toDeleteKeys = [];

    /**
     * Locales queued to be deleted entirely on commit (a membership set).
     *
     * @var array<string, true>
     */
    protected $toDeleteLocales = [];

    /**
     * Whether a full flush has been queued.
     * 
     * @var bool
     */
    protected $isFlushAllQueued = false;

    /**
     * Whether translations for every locale have been fetched.
     * 
     * @var bool
     */
    protected $isAllLoaded = false;

    /**
     * Create a new instance.
     * 
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Fetch and cache translations for a single locale, overwriting whatever was previously cached for it.
     *
     * @param string $locale
     * @return static
     */
    public function load(string $locale)
    {
        $this->translations[$locale] =
            app(ModelTranslationsRepository::class)->getModelTranslationsForLocale($this->model->getMorphClass(), $this->model->getKey(), $locale);

        return $this;
    }

    /**
     * Fetch and cache translations across all locales, replacing the current cache entirely.
     *
     * @return static
     */
    public function loadAll()
    {
        $this->translations =
            app(ModelTranslationsRepository::class)->getModelTranslations($this->model->getMorphClass(), $this->model->getKey());

        $this->isAllLoaded = true;

        return $this;
    }

    /**
     * Get cached translations without applying pending changes. Use `all()` or `forLocale()`
     * for resolved translations that reflect queued upserts/deletes/flush.
     *
     * @param string|null $locale
     * @return ($locale is null ? array<string, array<string, string>> : array<string, string>) Translations keyed by locale then attribute key, or by attribute key alone if a locale is given
     */
    public function getLoaded(?string $locale = null): array
    {
        if (filled($locale)) {
            return $this->translations[$locale] ?? [];
        }

        return $this->translations;
    }

    /**
     * Resolve a translation from cache, respecting queued upserts/deletes/flush.
     * Returns `null` if the key has no resolvable value for the given locale.
     * 
     * @param string $key
     * @param string $locale
     * @return string|null
     */
    public function get(string $key, string $locale): ?string
    {
        if (isset($this->toUpsert[$locale][$key])) {
            return $this->toUpsert[$locale][$key];
        }

        if (
            $this->isFlushAllQueued
            || isset($this->toDeleteLocales[$locale])
            || isset($this->toDeleteKeys[$key])
            || isset($this->toDelete[$locale][$key])
        ) {
            return null;
        }

        return $this->getLoaded($locale)[$key] ?? null;
    }

    /**
     * Resolve all translations currently known to the state, respecting queued upserts/deletes/flush.
     * 
     * Only reflects every locale and key in the database if `loadAll()` has been called first
     * (see `isAllLoaded()`); otherwise limited to locales and keys individually loaded or queued for upsert.
     *
     * @return array<string, array<string, string>> Translations keyed by locale then attribute key
     */
    public function all(): array
    {
        $loadedTranslations = $this->isFlushAllQueued ? [] : array_diff_key($this->getLoaded(), $this->toDeleteLocales);

        if (filled($this->toDelete) || filled($this->toDeleteKeys)) {
            foreach ($loadedTranslations as $translationsLocale => $translations) {
                $loadedTranslations[$translationsLocale] = array_diff_key($translations, $this->toDeleteKeys, $this->toDelete[$translationsLocale] ?? []);
            }
        }

        return array_filter(array_replace_recursive($loadedTranslations, $this->toUpsert), 'filled');
    }

    /**
     * Resolve locales currently known to the state, respecting queued upserts/deletes/flush.
     * 
     * Only reflects every locale in the database if `loadAll()` has been called first
     * (see `isAllLoaded()`); otherwise limited to locales individually loaded or queued for upsert.
     *
     * @return array<string>
     */
    public function locales(): array
    {
        return array_keys($this->all());
    }

    /**
     * Resolve all translations for a single attribute key, across every locale known to the state,
     * respecting queued upserts/deletes/flush.
     * 
     * Only reflects every locale in the database if `loadAll()` has been called first
     * (see `isAllLoaded()`); otherwise limited to locales individually loaded or queued for upsert.
     *
     * @param string $key
     * @return array<string, string> Translations keyed by locale
     */
    public function forKey(string $key): array
    {
        $loadedTranslations = [];

        if (! $this->isFlushAllQueued && ! isset($this->toDeleteKeys[$key])) {
            foreach ($this->getLoaded() as $translationsLocale => $translations) {
                if (
                    isset($translations[$key])
                    && ! isset($this->toDeleteLocales[$translationsLocale])
                    && ! isset($this->toDelete[$translationsLocale][$key])
                ) {
                    $loadedTranslations[$translationsLocale] = $translations[$key];
                }
            }
        }

        foreach ($this->toUpsert as $toUpsertTranslationsLocale => $toUpsertTranslations) {
            if (isset($toUpsertTranslations[$key])) {
                $loadedTranslations[$toUpsertTranslationsLocale] = $toUpsertTranslations[$key];
            }
        }

        return $loadedTranslations;
    }

    /**
     * Resolve all translations for a single locale, across every attribute key known to the state,
     * respecting queued upserts/deletes/flush.
     * 
     * Only reflects every key in the database if `loadAll()` has been called first
     * (see `isAllLoaded()`); otherwise limited to keys individually loaded or queued for upsert.
     *
     * @param string $locale
     * @return array<string, string> Translations keyed by attribute key
     */
    public function forLocale(string $locale): array
    {
        $loadedTranslations = $this->isFlushAllQueued ? [] :
            (isset($this->toDeleteLocales[$locale]) ? [] : ($this->getLoaded($locale)));

        $loadedTranslations = array_diff_key($loadedTranslations, $this->toDeleteKeys, $this->toDelete[$locale] ?? []);

        return array_replace($loadedTranslations, $this->toUpsert[$locale] ?? []);
    }

    /**
     * Get translations queued for upsert, keyed by locale then attribute key.
     *
     * @return array<string, array<string, string>>
     */
    public function queuedUpserts(): array
    {
        return $this->toUpsert;
    }

    /**
     * Get attribute keys queued for deletion, keyed by locale.
     *
     * @return array<string, array<string>>
     */
    public function queuedDeletes(): array
    {
        return array_map('array_keys', $this->toDelete);
    }

    /**
     * Get attribute keys queued to be deleted entirely.
     *
     * @return array<string>
     */
    public function queuedDeleteKeys(): array
    {
        return array_keys($this->toDeleteKeys);
    }

    /**
     * Get locales queued to be deleted entirely.
     *
     * @return array<string>
     */
    public function queuedDeleteLocales(): array
    {
        return array_keys($this->toDeleteLocales);
    }

    /**
     * Queue a translation for upsert, discarding any queued deletion for the same key/locale.
     * 
     * @param string $key
     * @param string $translation
     * @param string $locale
     * @return static
     */
    public function upsert(string $key, string $translation, string $locale)
    {
        unset($this->toDelete[$locale][$key]);

        $this->toUpsert[$locale][$key] = $translation;

        return $this;
    }

    /**
     * Queue a translation for deletion, discarding any queued upsert for the same key/locale.
     * 
     * @param string $key
     * @param string $locale
     * @return static
     */
    public function delete(string $key, string $locale)
    {
        unset($this->toUpsert[$locale][$key]);

        $this->toDelete[$locale][$key] = true;

        return $this;
    }

    /**
     * Queue one or more attribute keys to be deleted entirely,
     * discarding any queued upserts/deletes for those keys.
     *
     * @param array<string>|string $keys
     * @return static
     */
    public function deleteKeys($keys)
    {
        if (blank($keys)) {
            return $this;
        }

        $keys = array_fill_keys((array) $keys, true);

        foreach ($this->toUpsert as $toUpsertTranslationsLocale => $toUpsertTranslations) {
            $this->toUpsert[$toUpsertTranslationsLocale] = array_diff_key($toUpsertTranslations, $keys);
        }

        foreach ($this->toDelete as $toDeleteTranslationsLocale => $toDeletekeys) {
            $this->toDelete[$toDeleteTranslationsLocale] = array_diff_key($toDeletekeys, $keys);
        }

        $this->toDeleteKeys += $keys;

        return $this;
    }

    /**
     * Queue one or more locales to be deleted entirely,
     * discarding any queued upserts/deletes for keys under those locales.
     * 
     * @param array<string>|string $locales
     * @return static
     */
    public function deleteLocales($locales)
    {
        if (blank($locales)) {
            return $this;
        }

        $locales = (array) $locales;

        foreach ($locales as $locale) {
            unset($this->toUpsert[$locale]);
            unset($this->toDelete[$locale]);
        }

        $this->toDeleteLocales += array_fill_keys($locales, true);

        return $this;
    }

    /**
     * Queue every locale to be flushed entirely, discarding any queued upserts/deletes.
     * 
     * @return static
     */
    public function flushAll()
    {
        $this->toUpsert = [];
        $this->toDelete = [];
        $this->toDeleteKeys = [];
        $this->toDeleteLocales = [];

        $this->isFlushAllQueued = true;

        return $this;
    }

    /**
     * Determine whether translations for the given locale have been loaded into the cache.
     * 
     * @param string $locale
     * @return bool
     */
    public function isLoaded(string $locale): bool
    {
        return array_key_exists($locale, $this->getLoaded());
    }

    /**
     * Determine whether translations for every locale have been fetched via `loadAll()`,
     * meaning `all()`, `locales()`, `forKey()`, and `forLocale()` are guaranteed
     * to reflect the complete database state rather than just what's been
     * individually loaded or queued.
     * 
     * @return bool
     */
    public function isAllLoaded(): bool
    {
        return $this->isAllLoaded;
    }

    /**
     * Determine whether the given attribute key currently has a resolvable
     * translation for the specified locale, respecting queued upserts, deletes, and flush.
     *
     * @param string $key
     * @param string $locale
     * @return bool
     */
    public function has(string $key, string $locale): bool
    {
        return ! is_null($this->get($key, $locale));
    }

    /**
     * Determine whether any upserts, deletes, or flush are currently queued.
     * 
     * @return bool
     */
    public function hasPendingChanges(): bool
    {
        return filled($this->toUpsert)
            || filled($this->toDelete)
            || filled($this->toDeleteKeys)
            || filled($this->toDeleteLocales)
            || $this->isFlushAllQueued;
    }

    /**
     * Persist all queued actions to the database, in order: entire locale deletes,
     * then entire key deletes, then translation deletes, then translation upserts. Reconciles the cached
     * translations to match, then clears all queues.
     * 
     * @return void
     */
    public function commit(): void
    {
        if (! $this->hasPendingChanges()) {
            return;
        }

        $repository = app(ModelTranslationsRepository::class);
        $translatableType = $this->model->getMorphClass();
        $translatableId = $this->model->getKey();

        if ($this->isFlushAllQueued) {
            $repository->flushModelTranslations($translatableType, $translatableId);
        } else {
            $repository->deleteModelLocales($translatableType, $translatableId, $this->queuedDeleteLocales());
            $repository->deleteModelKeys($translatableType, $translatableId, $this->queuedDeleteKeys());
            $repository->deleteModelTranslations($translatableType, $translatableId, $this->queuedDeletes());
        }

        $repository->upsertModelTranslations($translatableType, $translatableId, $this->toUpsert);

        $this->translations = $this->all();

        $this->clear();
    }

    /**
     * Discard all queued upserts, deletes, and flush, leaving the cached translations untouched.
     * 
     * @return static
     */
    public function clear()
    {
        $this->toUpsert = [];
        $this->toDelete = [];
        $this->toDeleteKeys = [];
        $this->toDeleteLocales = [];
        $this->isFlushAllQueued = false;

        return $this;
    }

    /**
     * Discard all queued actions and the cached translations, returning to a blank slate.
     * 
     * @return static
     */
    public function reset()
    {
        $this->translations = [];
        $this->isAllLoaded = false;

        return $this->clear();
    }
}
