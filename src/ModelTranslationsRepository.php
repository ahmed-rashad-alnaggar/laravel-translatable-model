<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class ModelTranslationsRepository
{
    /**
     * The database connection instance.
     * 
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected $connection;

    /**
     * Creates a new instance.
     * 
     * @param \Illuminate\Database\ConnectionInterface $connection
     * @return void
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get all translations for a model in a specific locale.
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
     * Get all translations for a model across all locales.
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
     * Upsert translations for a model in a specific locale, `null` values will remove the corresponding translation.
     *
     * @param array<string, string> $translations
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return int
     */
    public function upsertModelTranslations(array $translations, string $translatableType, $translatableId, string $locale): int
    {
        $affectedRows = 0;

        [$translations, $translationsToRemove] = collect($translations)->partition(
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

        if ($translationsToRemove->isNotEmpty()) {
            $affectedRows += $this->removeModelTranslations($translationsToRemove->keys()->toArray(), $translatableType, $translatableId, $locale);
        }

        return $affectedRows;
    }

    /**
     * Remove translations for a model in a specific locale.
     *
     * @param array<string> $keys
     * @param string $translatableType
     * @param string|int $translatableId
     * @param string $locale
     * @return int
     */
    public function removeModelTranslations(array $keys, string $translatableType, $translatableId, string $locale): int
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->where('locale', '=', $locale)
            ->whereIn('key', $keys)
            ->delete();
    }

    /**
     * Remove all translations for a model across all locales.
     *
     * @param string $translatableType
     * @param string|int $translatableId
     * @return int
     */
    public function flushModelTranslations(string $translatableType, $translatableId): int
    {
        return $this->modelTranslations($translatableType, $translatableId)
            ->delete();
    }

    /**
     * Base query for a model's translations.
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
