<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;

class ModelTranslationsRepository
{
    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected ConnectionInterface $connection;

    /**
     * Create a new instance.
     *
     * @param \Illuminate\Database\ConnectionInterface $connection
     * @return void
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get all translations for the given translatable model in a specific locale.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return array<string, string>
     */
    public function getModelTranslationsForLocale(string $translatableType, string|int $translatableId, string $locale): array
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->where('locale', '=', $locale)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all translations for the given translatable model across all locales.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return array<string, array<string, string>>
     */
    public function getModelTranslations(string $translatableType, string|int $translatableId): array
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->get(['locale', 'key', 'value'])
            ->groupBy('locale')
            ->map->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all translatable attributes for the given translatable model.
     *
     * If no model ID is provided, translatable attributes from all instances of
     * the given translatable model are returned.
     *
     * @param string $translatableType
     * @param string|int|null $translatableId
     * @return array<string>
     */
    public function getModelKeys(string $translatableType, string|int|null $translatableId = null): array
    {
        return $this->table()
            ->where(
                blank($translatableId)
                ? ['translatable_type' => $translatableType]
                : ['translatable_type' => $translatableType, 'translatable_id' => $translatableId]
            )
            ->distinct()
            ->pluck('key')
            ->toArray();
    }

    /**
     * Get all translation locales for the given translatable model.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return array<string>
     */
    public function getModelLocales(string $translatableType, string|int $translatableId): array
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->distinct()
            ->pluck('locale')
            ->toArray();
    }

    /**
     * Upsert translations for the given translatable model across one or more locales;
     * `null` values will delete the corresponding translation.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @param array<string, array<string, string|null>> $translations Keyed by locale, then by attribute key.
     * @return int
     */
    public function upsertModelTranslations(string $translatableType, string|int $translatableId, array $translations): int
    {
        $affectedRows = 0;
        $records = [];
        $translationsToDelete = [];
        $timestamp = Date::now();

        foreach ($translations as $locale => $keyed) {
            foreach ($keyed as $key => $value) {
                if (is_null($value)) {
                    $translationsToDelete[$locale][] = $key;

                    continue;
                }

                $records[] = [
                    'translatable_type' => $translatableType,
                    'translatable_id' => $translatableId,
                    'locale' => $locale,
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if (filled($records)) {
            $affectedRows += $this->table()
                ->upsert(
                    $records,
                    ['translatable_type', 'translatable_id', 'locale', 'key'],
                    ['value', 'updated_at']
                );
        }

        if (filled($translationsToDelete)) {
            $affectedRows += $this->deleteModelTranslations($translatableType, $translatableId, $translationsToDelete);
        }

        return $affectedRows;
    }

    /**
     * Delete translations for the given translatable model.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @param array<string, array<string>> $translations Attribute keys keyed by locale
     * @return int
     */
    public function deleteModelTranslations(string $translatableType, string|int $translatableId, array $translations): int
    {
        if (blank($translations)) {
            return 0;
        }

        return $this->modelTranslations($translatableType, $translatableId)
            ->where(static function (Builder $query) use ($translations) {
                foreach ($translations as $locale => $keys) {
                    $query->orWhere(function (Builder $query) use ($locale, $keys) {
                        $query->where('locale', $locale)
                            ->whereIn('key', $keys);
                    });
                }
            })
            ->delete();
    }

    /**
     * Delete translations for the given keys for the given translatable model, across all locales.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @param array<string> $keys
     * @return int
     */
    public function deleteModelKeys(string $translatableType, string|int $translatableId, array $keys): int
    {
        if (blank($keys)) {
            return 0;
        }

        return $this->modelTranslations($translatableType, $translatableId)
            ->whereIn('key', $keys)
            ->delete();
    }

    /**
     * Delete all translations for the given translatable model in the given locales.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @param array<string> $locales
     * @return int
     */
    public function deleteModelLocales(string $translatableType, string|int $translatableId, array $locales): int
    {
        if (blank($locales)) {
            return 0;
        }

        return $this->modelTranslations($translatableType, $translatableId)
            ->whereIn('locale', $locales)
            ->delete();
    }

    /**
     * Delete all translations for the given translatable model across all locales.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return int
     */
    public function flushModelTranslations(string $translatableType, string|int $translatableId): int
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->delete();
    }

    /**
     * Delete all translations for a specific locale across all translatable models.
     *
     * @param string $locale
     * @return int
     */
    public function flushLocale(string $locale): int
    {
        return $this->table()
            ->where('locale', '=', $locale)
            ->delete();
    }

    /**
     * The base query for querying the given translatable model translations.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return \Illuminate\Database\Query\Builder
     */
    public function modelTranslations(string $translatableType, string|int $translatableId): Builder
    {
        return $this->table()
            ->where([
                'translatable_type' => $translatableType,
                'translatable_id' => $translatableId
            ]);
    }

    /**
     * Get a query builder for the translations table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function table(): Builder
    {
        return $this->connection->table('model_translations');
    }
}
