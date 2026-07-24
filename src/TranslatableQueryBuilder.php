<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

class TranslatableQueryBuilder extends Builder
{
    /**
     * The translatable model being queried.
     * 
     * @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations
     */
    protected $translatableModel;

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
        if (
            is_string($column)
            // Laravel doesn't support querying nested attributes
            // via a dot-notated string key (e.g. ->where('address.city')),
            // so do not handle it either.
            && ! Str::contains($column, '.')
        ) {
            $normalizedKey = str_replace('->', '.', $column);

            if ($this->translatableModel->isTranslatableAttribute($normalizedKey)) {
                $this->joinTranslation($normalizedKey);

                return parent::where(...([$this->getQualifiedTranslationValueColumn($normalizedKey)] + func_get_args()));
            }
        }

        return parent::where(...func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (is_string($column) && ! Str::contains($column, '.')) {
            $normalizedKey = str_replace('->', '.', $column);

            if ($this->translatableModel->isTranslatableAttribute($normalizedKey)) {
                $this->joinTranslation($normalizedKey);

                return parent::orderBy($this->getQualifiedTranslationValueColumn($normalizedKey), $direction);
            }
        }

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
     * Resolve a pluck column, attaching translation joins if necessary.
     * 
     * @param \Illuminate\Database\Query\Expression|string|null $column
     * @return \Illuminate\Database\Query\Expression|string|null
     * @internal
     */
    protected function resolvePluckColumn($column)
    {
        if (
            is_string($column)
            && ! Str::contains($column, '.')
            && $this->translatableModel->isTranslatableAttribute($column)
        ) {
            $this->joinTranslation($column);

            return $this->getQualifiedTranslationValueColumn($column)." as {$column}";
        }

        return $column;
    }

    /**
     * Left-join the translation record for the given key and locale.
     *
     * @param string $key
     * @param string|null $locale Defaults to the current app locale.
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
     * @param string|null $locale Defaults to the current app locale.
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
        return 't_'.substr(md5("{$key}.{$locale}"), 0, 12);
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
