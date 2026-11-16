<?php

namespace Alnaggar\Laravel\TranslatableModel\Concerns;

use Alnaggar\Laravel\TranslatableModel\Enums\TranslationsInterceptionMode;
use Alnaggar\Laravel\TranslatableModel\Facades\TranslatableModel;

trait HasTranslationsInterceptionMode
{
    /**
     * This instance's override of translation interception.
     *
     * @var \Alnaggar\Laravel\TranslatableModel\Enums\TranslationsInterceptionMode
     */
    protected TranslationsInterceptionMode $translationsInterceptionMode = TranslationsInterceptionMode::Global;

    /**
     * Get this instance's translation interception mode.
     *
     * @return \Alnaggar\Laravel\TranslatableModel\Enums\TranslationsInterceptionMode
     */
    public function getTranslationsInterceptionMode(): TranslationsInterceptionMode
    {
        return $this->translationsInterceptionMode;
    }

    /**
     * Set this instance's translation interception mode.
     *
     * @param \Alnaggar\Laravel\TranslatableModel\Enums\TranslationsInterceptionMode $mode
     * @return static
     */
    public function setTranslationsInterceptionMode(TranslationsInterceptionMode $mode): static
    {
        $this->translationsInterceptionMode = $mode;

        return $this;
    }

    /**
     * Force translation interception on for this instance, regardless of the facade's global toggle.
     *
     * @return static
     */
    public function enableTranslations(): static
    {
        return $this->setTranslationsInterceptionMode(TranslationsInterceptionMode::Enabled);
    }

    /**
     * Force translation interception off for this instance, regardless of the facade's global toggle.
     *
     * @return static
     */
    public function disableTranslations(): static
    {
        return $this->setTranslationsInterceptionMode(TranslationsInterceptionMode::Disabled);
    }

    /**
     * Determine whether translation interception is disabled for this instance, resolving
     * this instance's mode and, if global, the facade's global toggle.
     *
     * @return bool
     */
    public function isTranslationsDisabled(): bool
    {
        return match ($this->translationsInterceptionMode) {
            TranslationsInterceptionMode::Enabled => false,
            TranslationsInterceptionMode::Disabled => true,
            TranslationsInterceptionMode::Global => TranslatableModel::isTranslationsDisabled(),
        };
    }
}
