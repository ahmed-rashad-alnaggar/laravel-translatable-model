<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

class TranslatableQueryBuilder extends Builder
{
    /**
     * The model that owns this query builder.
     *
     * @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations
     */
    protected $translatableModel;

    /**
     * Set the translatable model this builder is querying for.
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
            // via a dot-notated string key (e.g. ->where('address.city')).
            && ! Str::contains($column, '.')
        ) {
            $normalizedKey = str_replace('->', '.', $column);

            if ($this->translatableModel->isTranslatableAttribute($normalizedKey)) {
                $this->joinTranslation($normalizedKey);

                return parent::where(...([$this->getQualifiedTranslationValueColumnForKey($normalizedKey)] + func_get_args()));
            }
        }

        return parent::where(...func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (
            is_string($column)
            && ! Str::contains($column, '.')
        ) {
            $normalizedKey = str_replace('->', '.', $column);

            if ($this->translatableModel->isTranslatableAttribute($normalizedKey)) {
                $this->joinTranslation($normalizedKey);

                return parent::orderBy($this->getQualifiedTranslationValueColumnForKey($normalizedKey), $direction);
            }
        }

        return parent::orderBy($column, $direction);
    }

    /**
     * Left-join `model_translations` for the given key/locale, so it can
     * be referenced by `where()` / `orderBy()` dependent clauses.
     *
     * @param string $translationKey
     * @param string|null $locale Defaults to the current app locale.
     * @return void
     */
    public function joinTranslation(string $translationKey, ?string $locale = null): void
    {
        $translationsTableAlias = $this->getTranslationsTableAliasForKey($translationKey);

        if ($this->hasJoinedTranslationsTableForKey($translationKey)) {
            // Reuse the existing join for repeated constraints on the same key.
            return;
        }

        $modelTable = $this->translatableModel->getTable();
        $modelPrimaryKeyName = $this->translatableModel->getKeyName();
        $modelMorphClass = $this->translatableModel->getMorphClass();
        $locale = $locale ?? app()->currentLocale();

        $this->leftJoin("model_translations as {$translationsTableAlias}", function (JoinClause $join) use ($translationsTableAlias, $modelTable, $modelPrimaryKeyName, $modelMorphClass, $translationKey, $locale) {
            $join->on("{$translationsTableAlias}.translatable_id", '=', $modelTable.'.'.$modelPrimaryKeyName)
                ->where("{$translationsTableAlias}.translatable_type", '=', $modelMorphClass)
                ->where("{$translationsTableAlias}.key", '=', $translationKey)
                ->where("{$translationsTableAlias}.locale", '=', $locale);
        });
    }

    /**
     * Get the fully qualified `value` column for the translation join
     * corresponding to the given key.
     * 
     * @param string $key
     * @return string
     */
    public function getQualifiedTranslationValueColumnForKey(string $key): string
    {
        return $this->getTranslationsTableAliasForKey($key).'.value';
    }

    /**
     * Determine whether the translations table has already been joined
     * for the given translation key.
     * 
     * @param string $key
     * @return bool
     */
    protected function hasJoinedTranslationsTableForKey(string $key): bool
    {
        $tableAlias = $this->getTranslationsTableAliasForKey($key);

        foreach ((array) $this->joins as $join) {
            if ($join->table === "model_translations as {$tableAlias}") {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the deterministic table alias used for joins of the given
     * translation key.
     * 
     * @param string $key
     * @return string
     */
    protected function getTranslationsTableAliasForKey(string $key): string
    {
        return 't_'.substr(md5($key), 0, 12);
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
