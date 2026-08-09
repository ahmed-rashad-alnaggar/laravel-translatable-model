<?php

namespace Alnaggar\TranslatableModel;

use Alnaggar\TranslatableModel\FallbackStrategies\DefaultLocaleFallbackStrategy;
use Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy;

class TranslatableModelManager
{
    /**
     * Whether translation interception is currently disabled.
     *
     * @var bool
     */
    protected bool $isTranslationsDisabled = false;

    /**
     * Cached, resolved default fallback strategy.
     *
     * @var \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy
     */
    protected FallbackStrategy $defaultFallbackStrategy;

    /**
     * Run the given callback with translation interception disabled,
     * for every translatable model.
     *
     * @param callable $callback
     * @return mixed
     */
    public function withoutTranslations(callable $callback): mixed
    {
        $previouslyDisabled = $this->isTranslationsDisabled;
        $this->isTranslationsDisabled = true;

        try {
            return $callback();
        } finally {
            $this->isTranslationsDisabled = $previouslyDisabled;
        }
    }

    /**
     * Whether translation interception is currently disabled.
     *
     * @return bool
     */
    public function isTranslationsDisabled(): bool
    {
        return $this->isTranslationsDisabled;
    }

    /**
     * The database connection to use for the translations table.
     *
     * @return string|null
     */
    public function connection(): ?string
    {
        return config('translatable-model.connection');
    }

    /**
     * The translations default fallback strategy.
     *
     * @return \Alnaggar\TranslatableModel\FallbackStrategies\FallbackStrategy
     */
    public function defaultFallbackStrategy(): FallbackStrategy
    {
        return $this->defaultFallbackStrategy ??= FallbackStrategy::make(
            config('translatable-model.fallback_strategy', DefaultLocaleFallbackStrategy::class)
        );
    }

    /**
     * Whether translations should be flushed when a model is soft-deleted.
     *
     * @return bool
     */
    public function shouldFlushTranslationsOnSoftDelete(): bool
    {
        return (bool) config('translatable-model.flush_translations_on_soft_delete', false);
    }
}
