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
    protected $connection;

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
     * Get all translatable attributes for the given translatable model.
     *
     * If no model ID is provided, translatable attributes from all instances of
     * the given translatable model are returned.
     *
     * @param string $translatableType
     * @param string|int|null $translatableId
     * @return array<string>
     */
    public function getModelTranslatableAttributes(string $translatableType, $translatableId = null): array
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
     * Get all translations for the given translatable model in a specific locale.
     * 
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return array<string, string>
     */
    public function getModelTranslationsForLocale(string $translatableType, $translatableId, string $locale): array
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
    public function getModelTranslations(string $translatableType, $translatableId): array
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->get(['locale', 'key', 'value'])
            ->groupBy('locale')
            ->map->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all translation locales for the given translatable model.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return array<string>
     */
    public function getModelLocales(string $translatableType, $translatableId): array
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->distinct()
            ->pluck('locale')
            ->toArray();
    }

    /**
     * Upsert translations for the given translatable model in a specific locale; `null` values will delete the corresponding translation.
     *
     * @param array<string, string|null> $translations
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return int
     */
    public function upsertModelTranslationsForLocale(array $translations, string $translatableType, $translatableId, string $locale): int
    {
        $affectedRows = 0;

        [$translations, $translationsToDelete] = collect($translations)->partition(
            static function (?string $translation): bool {
                return ! is_null($translation);
            }
        );

        $records = [];

        foreach ($translations as $key => $value) {
            $timestamp = Date::now();

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

        $affectedRows += $this->table()
            ->upsert(
                $records,
                ['translatable_type', 'translatable_id', 'locale', 'key'],
                ['value', 'updated_at']
            );

        if ($translationsToDelete->isNotEmpty()) {
            $affectedRows += $this->deleteModelTranslationsForLocale($translationsToDelete->keys()->toArray(), $translatableType, $translatableId, $locale);
        }

        return $affectedRows;
    }

    /**
     * Delete translations for the given translatable model in a specific locale.
     *
     * @param array<string> $keys
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return int
     */
    public function deleteModelTranslationsForLocale(array $keys, string $translatableType, $translatableId, string $locale): int
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->where('locale', '=', $locale)
            ->whereIn('key', $keys)
            ->delete();
    }

    /**
     * Delete all translations for the given translatable model in a specific locale.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return int
     */
    public function deleteAllModelTranslationsForLocale(string $translatableType, $translatableId, string $locale): int
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->where('locale', '=', $locale)
            ->delete();
    }

    /**
     * Delete translations for the given translatable model across all locales.
     *
     * @param array<string> $keys
     * @param string $translatableType
     * @param string|int $translatableId
     * @return int
     */
    public function flushModelTranslations(array $keys, string $translatableType, $translatableId): int
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->whereIn('key', $keys)
            ->delete();
    }

    /**
     * Delete all translations for the given translatable model across all locales.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return int
     */
    public function flushAllModelTranslations(string $translatableType, $translatableId): int
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
    public function modelTranslations(string $translatableType, $translatableId): Builder
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
