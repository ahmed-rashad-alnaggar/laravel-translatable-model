<?php

namespace Alnaggar\TranslatableModel\Concerns;

use Alnaggar\TranslatableModel\Facades\TranslatableModel;
use Illuminate\Database\Eloquent\Model;

trait HandlesSoftDeleteTranslations
{
    /**
     * Boot the HandlesSoftDeleteTranslations trait. 
     *
     * @return void
     */
    public static function bootHandlesSoftDeleteTranslations(): void
    {
        // Flush all related translations when the model is deleted, with respect to soft-deletes.
        static::deleted(static function (/** @var \Illuminate\Database\Eloquent\Model&\Alnaggar\TranslatableModel\HasTranslations $model */ Model $model): void {
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
     * Determine if translations should be flushed when the model is soft-deleted.
     *
     * @return bool
     */
    protected function shouldFlushTranslationsOnSoftDelete(): bool
    {
        return TranslatableModel::shouldFlushTranslationsOnSoftDelete();
    }
}
