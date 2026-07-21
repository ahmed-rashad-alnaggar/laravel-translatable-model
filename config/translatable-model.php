<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection used to store and retrieve translations.
    | If set to null, the application's default database connection will be used.
    |
    */
    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Missing Translation Fallback Strategy
    |--------------------------------------------------------------------------
    |
    | Controls how the system behaves when a translatable attribute's
    | translation for the requested locale is missing.
    | Only applies to models that don't declare their own $defaultTranslationsFallbackStrategy property.
    |
    | Supported values:
    |
    | - A strategy class-string.
    |
    | - "ClassName:arg1,arg2"
    |     A strategy class-string with constructor arguments,
    |     e.g. DedicatedLocaleFallbackStrategy::class.':en' to fallback to 'en'.
    |
    */
    'fallback_strategy' => \Alnaggar\TranslatableModel\FallbackStrategies\DefaultLocaleFallbackStrategy::class,

    /*
    |--------------------------------------------------------------------------
    | Remove Translations On Soft Delete
    |--------------------------------------------------------------------------
    |
    | When true, translations will be flushed when their model is soft-deleted.
    | When false (default), translations are only flushed on a force-delete.
    |
    */
    'flush_translations_on_soft_delete' => false,
    
];
