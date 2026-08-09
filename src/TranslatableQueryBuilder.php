<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

class TranslatableQueryBuilder extends Builder
{
    /**
     * The translatable model being queried.
     *
     * @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations
     */
    protected Model $translatableModel;

    /**
     * Set the translatable model being queried.
     *
     * @param \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model
     * @return static
     */
    public function setTranslatableModel(Model $model)
    {
        $this->translatableModel = $model;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $column = $this->resolveQueryColumn($column);

        return parent::where(...([$column] + func_get_args()));
    }

    /**
     * {@inheritDoc}
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $column = $this->resolveQueryColumn($column);

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * {@inheritDoc}
     */
    public function orderBy($column, $direction = 'asc')
    {
        $column = $this->resolveQueryColumn($column);

        return parent::orderBy($column, $direction);
    }

    /**
     * {@inheritDoc}
     */
    public function pluck($column, $key = null)
    {
        return parent::pluck(
            $this->resolvePluckColumn($column),
            $this->resolvePluckColumn($key)
        );
    }

    /**
     * Resolve a query column, joining its translation — for the current app locale —
     * when it is a literal translatable attribute.
     *
     * @param mixed $column
     * @return mixed
     */
    protected function resolveQueryColumn(mixed $column): mixed
    {
        if (
            ! is_string($column)
            // Laravel doesn't support querying nested attributes
            // via a dot-notated key (e.g. $model->where('city.name')),
            // so do not handle it either.
            || str_contains($column, '.')
        ) {
            return $column;
        }

        // Laravel supports querying nested attributes
        // via a JSON selector key (e.g. $model->where('city->name')).
        $normalizedKey = str_replace('->', '.', $column);

        if (! isset($this->translatableModel->getCachedTranslatablesMap()['literals'][$normalizedKey])) {
            return $column;
        }

        $this->joinTranslation($normalizedKey);

        return $this->getQualifiedTranslationValueColumn($normalizedKey);
    }

    /**
     * Resolve a pluck column, joining its translation — for the current app locale —
     * when it is a translatable **column**.
     *
     * @param mixed $column
     * @return mixed
     */
    protected function resolvePluckColumn(mixed $column): mixed
    {
        if (
            ! is_string($column)
            // Laravel's pluck does not support dot-notated or JSON selector keys.
            || str_contains($column, '.')
            || str_contains($column, '->')
            || ! isset($this->translatableModel->getCachedTranslatablesMap()['literals'][$column])
        ) {
            return $column;
        }

        $this->joinTranslation($column);

        return $this->getQualifiedTranslationValueColumn($column)." as {$column}";
    }

    /**
     * Left-join the translation record for the given key and locale.
     *
     * @param string $key
     * @param string|null $locale Translation locale; defaults to app locale.
     * @return void
     */
    public function joinTranslation(string $key, ?string $locale = null): void
    {
        $locale = $locale ?? app()->currentLocale();
        $translationsTableAlias = $this->getTranslationsTableAlias($key, $locale);

        if ($this->hasJoinedTranslation($key, $locale)) {
            // Reuse the existing join for repeated constraints on the same key.
            return;
        }

        $modelTable = $this->translatableModel->getTable();
        $modelPrimaryKeyName = $this->translatableModel->getKeyName();
        $modelMorphClass = $this->translatableModel->getMorphClass();

        $this->leftJoin("model_translations as {$translationsTableAlias}", static function (JoinClause $join) use ($translationsTableAlias, $modelTable, $modelPrimaryKeyName, $modelMorphClass, $key, $locale) {
            $join->on("{$translationsTableAlias}.translatable_id", '=', $modelTable.'.'.$modelPrimaryKeyName)
                ->where("{$translationsTableAlias}.translatable_type", '=', $modelMorphClass)
                ->where("{$translationsTableAlias}.key", '=', $key)
                ->where("{$translationsTableAlias}.locale", '=', $locale);
        });
    }

    /**
     * Get the fully qualified `value` column for the translation record join for the given key and locale.
     *
     * @param string $key
     * @param string|null $locale Translation locale; defaults to app locale.
     * @return string
     */
    public function getQualifiedTranslationValueColumn(string $key, ?string $locale = null): string
    {
        $locale = $locale ?? app()->currentLocale();

        return $this->getTranslationsTableAlias($key, $locale).'.value';
    }

    /**
     * Determine whether the translation record has already been joined for the given key and locale.
     *
     * @param string $key
     * @param string $locale
     * @return bool
     */
    protected function hasJoinedTranslation(string $key, string $locale): bool
    {
        $tableAlias = $this->getTranslationsTableAlias($key, $locale);

        foreach ((array) $this->joins as $join) {
            if ($join->table === "model_translations as {$tableAlias}") {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the deterministic table alias used for joining the translation record for the given key and locale.
     *
     * @param string $key
     * @param string $locale
     * @return string
     */
    protected function getTranslationsTableAlias(string $key, string $locale): string
    {
        return 't_'.crc32("{$key}.{$locale}");
    }

    /**
     * {@inheritDoc}
     */
    public function newQuery()
    {
        return (new static($this->connection, $this->grammar, $this->processor))
            ->setTranslatableModel($this->translatableModel);
    }
}
