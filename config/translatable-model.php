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
    | Missing Translation Fallback Behavior
    |--------------------------------------------------------------------------
    |
    | Controls how the system behaves when a translatable attribute's
    | translation for the requested locale is missing.
    |
    | Supported values:
    |
    | - string (locale):
    |     Fallback to the specified locale.
    |
    | - true | null:
    |     Fallback to the application's fallback locale.
    |
    | - false:
    |     Do not fallback to any locale. Return null.
    |
    */
    'fallback_behavior' => null,

    /*
    |--------------------------------------------------------------------------
    | Remove Translations On Soft Delete
    |--------------------------------------------------------------------------
    |
    | When true, translations will be flushed when a model is soft-deleted.
    | When false (default), translations are only flushed on a force-delete.
    |
    */
    'flush_translations_on_soft_delete' => false,
    
];
