<?php

namespace Alnaggar\Laravel\TranslatableModel\Concerns;

use Alnaggar\Laravel\TranslatableModel\ModelTranslationsState;
use Illuminate\Database\Eloquent\Model;

trait HasTranslationsState
{
    /**
     * The translations state for this model instance.
     *
     * @var \Alnaggar\Laravel\TranslatableModel\ModelTranslationsState
     */
    protected ModelTranslationsState $translationsState;

    /**
     * Boot the HasTranslationsState trait. 
     *
     * @return void
     */
    public static function bootHasTranslationsState(): void
    {
        // Defer saving/deleting translations until the model is saved.
        static::saved(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\Laravel\TranslatableModel\HasTranslations $model */ Model $model): void {
            $model->getTranslationsState()->commit();
        });
    }

    /**
     * Initialize the HasTranslationsState trait. 
     *
     * @return void
     */
    public function initializeHasTranslationsState(): void
    {
        $this->translationsState = new ModelTranslationsState($this);
    }

    /**
     * Get the translations state for this model instance.
     *
     * @return \Alnaggar\Laravel\TranslatableModel\ModelTranslationsState
     */
    public function getTranslationsState(): ModelTranslationsState
    {
        return $this->translationsState;
    }
}
