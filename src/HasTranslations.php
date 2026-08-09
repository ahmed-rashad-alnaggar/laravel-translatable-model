<?php

namespace Alnaggar\TranslatableModel;

use Alnaggar\TranslatableModel\Concerns;
use Alnaggar\TranslatableModel\Facades\TranslatableModel;
use Illuminate\Support\Str;

trait HasTranslations
{
    use Concerns\HandlesSoftDeleteTranslations,
        Concerns\HasDefaultFallbackStrategy,
        Concerns\HasTranslationsState,
        Concerns\InteractsWithTranslatableAttributes,
        Concerns\InteractsWithTranslatableAttributeValues,
        Concerns\ManagesTranslations;

    /**
     * {@inheritDoc}
     */
    protected function newBaseQueryBuilder()
    {
        if (TranslatableModel::isTranslationsDisabled()) {
            return parent::newBaseQueryBuilder();
        }

        $connection = $this->getConnection();
        $grammar = $connection->getQueryGrammar();
        $processor = $connection->getPostProcessor();

        return app(TranslatableQueryBuilder::class, compact('connection', 'grammar', 'processor'))
            ->setTranslatableModel($this);
    }

    /**
     * {@inheritDoc}
     */
    public function getAttributeValue($key)
    {
        if (
            ! TranslatableModel::isTranslationsDisabled()
            && $key !== $this->getKeyName()
            // Translations only apply to loaded database columns — a translatable column
            // skipped by a `select()` won't have translations applied.
            && array_key_exists($key, $this->attributes)
        ) {
            if ($this->isTranslatableAttribute($key)) {
                $value = $this->getTranslatableColumnValue($key, app()->currentLocale(), $this->getDefaultTranslationsFallbackStrategy());

                return $this->transformModelValue($key, $value);
            }

            if ($this->isNestingTranslatableAttributes($key)) {
                $value = $this->getColumnNestingTranslatablesValue($key, app()->currentLocale(), $this->getDefaultTranslationsFallbackStrategy());

                return $this->transformModelValue($key, $value);
            }
        }

        return parent::getAttributeValue($key);
    }

    /**
     * {@inheritDoc}
     */
    protected function getArrayableAttributes()
    {
        $arrayableAttributes = parent::getArrayableAttributes();

        if (TranslatableModel::isTranslationsDisabled()) {
            return $arrayableAttributes;
        }

        $locale = app()->currentLocale();
        $fallbackStrategy = $this->getDefaultTranslationsFallbackStrategy();

        foreach (array_keys($arrayableAttributes) as $key) {
            if ($this->isTranslatableAttribute($key)) {
                $arrayableAttributes[$key] = $this->getTranslatableColumnValue($key, $locale, $fallbackStrategy);
            } elseif ($this->isNestingTranslatableAttributes($key)) {
                $arrayableAttributes[$key] = $this->getColumnNestingTranslatablesValue($key, $locale, $fallbackStrategy);
            }
        }

        return $arrayableAttributes;
    }

    /**
     * {@inheritDoc}
     */
    public function setAttribute($key, $value)
    {
        if (TranslatableModel::isTranslationsDisabled()) {
            return parent::setAttribute($key, $value);
        }

        $column = Str::before($key, '.');

        if ($this->isTranslatableAttribute($column)) {
            return $this->setTranslatableColumn($column, $value, app()->currentLocale());
        }

        if ($this->isNestingTranslatableAttributes($column)) {
            return $this->setColumnNestingTranslatables($column, $value, app()->currentLocale());
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function fillJsonAttribute($key, $value)
    {
        if (TranslatableModel::isTranslationsDisabled()) {
            return parent::fillJsonAttribute($key, $value);
        }

        $normalizedKey = str_replace('->', '.', $key);

        if ($this->isTranslatableAttribute($normalizedKey)) {
            return $this->fillTranslatableJsonAttribute($normalizedKey, $value, app()->currentLocale());
        }

        if ($this->isNestingTranslatableAttributes($normalizedKey)) {
            return $this->fillJsonAttributeNestingTranslatables($normalizedKey, $value, app()->currentLocale());
        }

        return parent::fillJsonAttribute($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function discardChanges()
    {
        $this->getTranslationsState()->clear();

        return parent::discardChanges();
    }

    /**
     * {@inheritDoc}
     */
    public function refresh()
    {
        if ($this->exists) {
            $this->getTranslationsState()->clear();
        }

        return parent::refresh();
    }
}
