# Laravel Translatable Model

![I Stand With Palestine Badge](./arts/PalestineBadge.svg)

![I Stand With Palestine Banner](./arts/PalestineBanner.svg)

[![Latest Stable Version](https://img.shields.io/packagist/v/alnaggar/laravel-translatable-model)](https://packagist.org/packages/alnaggar/laravel-translatable-model)
[![Total Downloads](https://img.shields.io/packagist/dt/alnaggar/laravel-translatable-model)](https://packagist.org/packages/alnaggar/laravel-translatable-model)
[![License](https://img.shields.io/packagist/l/alnaggar/laravel-translatable-model)](https://packagist.org/packages/alnaggar/laravel-translatable-model)

A package that stores model attribute translations in a separate database table. It supports literal translatable attributes, including [nested attributes](#nested-translatables), [wildcard-declared](#wildcard-translatables) attributes inside repeatable list-shaped data, and [dynamic discovery](#dynamic-translatables) for models without a fixed set of translatable attributes.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Migration](#migration)
- [Usage](#usage)
- [Nested Translatables](#nested-translatables)
- [Wildcard Translatables](#wildcard-translatables)
- [Dynamic Translatables](#dynamic-translatables)
- [Fallback Strategies](#fallback-strategies)
- [Casting](#casting)
- [Querying](#querying)
- [Disabling Translations](#disabling-translations)
- [API Reference](#api-reference)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Requirements

- PHP 8.2+
- Laravel 12.3+

## Installation

1. Install the package using Composer:

    ```bash
    composer require alnaggar/laravel-translatable-model
    ```

2. Publish the configuration and migration files:

    ```bash
    php artisan vendor:publish --tag="translatable-model-config"
    ```

    ```bash
    php artisan vendor:publish --tag="translatable-model-migrations"
    ```

3. Run the migration:

    ```bash
    php artisan migrate
    ```

## Configuration

The published config file is `config/translatable-model.php`:

| Option                              | Type      | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ----------------------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `connection`                        | `?string` | Database connection used for the translations table. `null` uses the app's default connection.                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `fallback_strategy`                 | `string`  | Default [fallback strategy](#fallback-strategies) used when normally accessing a translatable attribute (`$model->attribute`, `$model['attribute']`, `$model->attributesToArray()`, etc.) and its translation for the current locale is missing. Only applies to models that don't override `getDefaultTranslationsFallbackStrategy()` themselves. Accepts a strategy class-string, or a `"ClassName:arg1,arg2"` string for a strategy that takes constructor arguments (e.g. `DedicatedLocaleFallbackStrategy::class.':en'`). |
| `flush_translations_on_soft_delete` | `bool`    | When `true`, translations are flushed when a model is soft-deleted. When `false` (default), translations are only flushed on a force-delete.                                                                                                                                                                                                                                                                                                                                                                                   |

## Migration

The package publishes a migration that creates the `model_translations` table:

| Column                      | Type               | Notes                                                                                                                                                                                    |
| --------------------------- | ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `translatable_type`         | `string(100)`      | The model's morph class                                                                                                                                                                  |
| `translatable_id`           | `string(36)`       | Stored as a string so both numeric and string (e.g. UUID) primary keys work                                                                                                              |
| `locale`                    | `string(10)`       |                                                                                                                                                                                          |
| `key`                       | `string(255)`      | The translation key — identical to the attribute name for a literal translatable; see [Wildcard Translatables](#wildcard-translatables) for how this differs for a wildcard-declared one |
| `value`                     | `text`, `nullable` |                                                                                                                                                                                          |
| `created_at` / `updated_at` | `timestamps`       |                                                                                                                                                                                          |

The table has a composite primary key on `(translatable_type, translatable_id, locale, key)`.

## Usage

Add the `HasTranslations` trait to any Eloquent model and declare translatable attributes by overriding `translatables()`.

```php
use Alnaggar\TranslatableModel\HasTranslations;

class Post extends Model
{
    use HasTranslations;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    protected function translatables(): array
    {
        return [
            'title',
            'body',
            'meta.title',
            'meta.description',
        ];
    }
}
```

The declared column(s) — `title` and `meta` here — hold a *placeholder* as their raw database value, not the real translated text, which lives entirely in `model_translations`. The placeholder can be anything: whatever the column held before a translation was ever set, or a value you set deliberately (see [Disabling Translations](#disabling-translations) for writing one directly).

> [!WARNING]
> A translatable attribute, [dynamic](#dynamic-translatables) or not, must always correspond to a real model attribute: either a model column itself or a nested attribute within one. That model column must also be loaded on the model — if it was excluded from the query using `select()`, for example, the attribute **will not be intercepted during normal model attribute access (except JSON selector access)**, and accessing it through **CRUD access or JSON selector access will throw an exception**.

### Get translation(s)

```php
// Laravel-style retrievement in current locale, applying the model's
// (or config's) default fallback strategy, then fall back to the placeholder
$titleAr = $post->title;
$titleAr = $post['title'];

$titleAr = $post->getTranslation(
    key: 'title',
    locale: 'ar', // null for current locale
    fallbackStrategy: DedicatedLocaleFallbackStrategy::class.':en' // see Fallback Strategies
);

// Every locale that currently has a translation for this key
$allTitles = $post->getTranslations(
    key: 'title',
    fallbackStrategy: null // Applying the model's (or config's) default fallback strategy
);
// ['ar' => 'مرحبا بالعالم', 'en' => 'Hello world', 'fr' => 'Bonjour à tous']
```

> [!NOTE]
> Normal attribute access (`$model->attribute`, `$model['attribute']`, `$model->attributesToArray()`, etc.) falls back further than the CRUD API (`getTranslation()`, `getTranslations()`) does. If neither the requested locale nor the fallback strategy resolves anything (e.g. `NoFallbackStrategy`, or any strategy whose `missing()` returns `null`), attribute access returns the column's raw **placeholder** value instead of `null`. Calling `getTranslation()`/`getTranslations()` directly does **not** do this — it returns exactly whatever the fallback strategy's `missing()` produces (`null` by default, or e.g. `KeyPlaceholderFallbackStrategy`'s `"{locale}.{key}"`), and never falls back further to the placeholder.

### Set translation(s)

```php
// Laravel-style assignment in current locale
$post->title = 'Bonjour à tous';
$post['title'] = 'Bonjour à tous';

$post->setTranslation(
    key: 'title',
    translation: 'Hello world',
    locale: 'en' // null for current locale
);

$post->setTranslations(
    key: 'title',
    translations: ['ar' => 'مرحبا', 'en' => 'Hello', 'fr' => 'Bonjour à tous']
);

// Translations are upserted when the model is saved
$post->save();
```

> [!NOTE]
> Setting a translation value to `null` is interpreted as a deletion for that key/locale, not a stored empty value.

### Remove translation(s)

```php
$post->removeTranslation(
    key: 'meta.description',
    locale: 'fr' // null for current locale
); 

$post->removeTranslationsForKeys(['title', 'meta.description']); // every translation for these keys across all locales
$post->removeTranslationsForLocales('fr'); // every translation for this locale (accepts an array too)
$post->flushAllTranslations(); // everything

$post->save();
```

### Checking translation existence

```php
if ($post->hasTranslation('title', 'ar')) {
    // Arabic translation exists
}
```

### Persisting behavior

All translation operations are queued on the model instance and only persisted when the model **is saved** — nothing hits the database until `save()`.

**Deleting the model flushes all its translations automatically.** For models using `SoftDeletes`, translations are flushed only on a force-delete by default; set `flush_translations_on_soft_delete` to `true` in the package config (or override `shouldFlushTranslationsOnSoftDelete()` on the model) to flush on soft-delete too.

> [!WARNING]
> Persisting queued translations relies on the model's save-related events firing — that's the mechanism that actually commits them to the database when `save()` runs. Anything that suppresses model events means `save()` succeeds and the model's own columns are written normally, but **queued translations are silently never persisted**. This includes Laravel's `WithoutModelEvents` trait (commonly used in seeders/tests), `Model::withoutEvents(...)`, and quiet variants like `saveQuietly()`/`updateQuietly()`/`deleteQuietly()` — any case where model events are disabled, not just these specific ones.

## Nested Translatables

A translatable key can target data nested inside any attribute whose value is array-accessible — a plain `array` cast, a `Collection`, `AsArrayObject`/`AsCollection`, or any custom cast whose decoded value can be traversed the same way. Declaring `meta.description` translatable makes only that leaf translatable, leaving the rest of `meta` as ordinary data:

```php
protected function translatables(): array
{
    return ['meta.description'];
}
```

```php
$post->meta; // => ['author' => 'Ahmad', 'description' => 'Translated value']

$post->meta = ['author' => 'Ahmad', 'description' => 'A translations management project'];
// or, targeting just the leaf directly:
$post['meta->description'] = 'A translations management project';

$post->save();
```

## Wildcard Translatables

For a collection of items — however it's cast (a plain `array`, a `Collection`, a custom `Castable`, etc.) — where *each item* has a translatable field, e.g. product variants, FAQ entries, declare the attribute with a `*` in place of the array index:

```php
protected function translatables(): array
{
    return ['variants.*.label']; // matches variants.0.label, variants.1.label, ...
}
```

```php
$product->variants = [
    ['id' => 1, 'sku' => 'RED-M', 'label' => null],
    ['id' => 2, 'sku' => 'BLU-M', 'label' => null],
];
$product->save();

$product->{'variants->0->label'} = 'Red / Medium';
$product->{'variants->1->label'} = 'Blue / Medium';
$product->save();
```

Each item's translation is tracked by **identity**, not array position — using the item's `id` field by default. Reordering, inserting, or removing sibling items never scrambles or loses another item's translation, because the stored translation key is resolved from `id`, not from the item's current index.

If your items don't use `id`, declare a custom identity field with `*:fieldName`:

```php
protected function translatables(): array
{
    return ['items.*:uuid.answer'];
}
```

> [!WARNING]
> Every item must actually carry its identity field (`id` by default, or whatever `*:fieldName` names) for its translation to be tracked. An item missing that field cannot have its translation key resolved and **will result in an exception**.

> [!NOTE]
> The exact string format used internally for a wildcard-resolved storage key (visible if you inspect `model_translations.key` directly) is an implementation detail. Don't parse it or write queries against its literal shape — it may change between versions. Use the model's own API (`getTranslation()`, `getTranslatables()`, etc.) instead.

## Dynamic Translatables

For a model with no fixed set of translatable attributes — e.g. a `Setting` model whose translatable keys aren't known ahead of time — override `hasDynamicTranslatables()`:

```php
class Setting extends Model
{
    use HasTranslations;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public function hasDynamicTranslatables(): bool
    {
        return true;
    }
}
```

With this enabled, the model's translatable attributes are *discovered* from what's already stored in `model_translations`, instead of coming from `translatables()`.

> [!NOTE]
> `hasDynamicTranslatables()` and `translatables()` are mutually exclusive, not merged — a dynamic model's static `translatables()` (if any) is ignored entirely in favor of discovery.

A dynamic key only needs to be registered explicitly with `rememberDynamicTranslatable()` when it is being translated **for the first time** — that is, when no translation for the key has ever been stored, or when all existing translations for that key have since been deleted. Once a translation exists, the key is discovered automatically on subsequent use.

For example, in a seeder, register the key before assigning its first translation:

```php
$generalSettings = Setting::create([
    'key' => 'general_settings',
    'value' => []
]);

$generalSettings->rememberDynamicTranslatable('value.app_name');
$generalSettings->setTranslation('value.app_name', 'Laravel Translatable Model', 'en');
$generalSettings->save();

$settings = $generalSettings->value; // => ['app_name' => 'Laravel Translatable Model'] — translated, current locale
```

## Fallback Strategies

A fallback strategy controls what happens when a translation is missing for the requested locale. Five are included:

| Strategy                                       | Behavior                                                                                                                                                      |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DefaultLocaleFallbackStrategy`                | Falls back to `app()->getFallbackLocale()`. Used by default if nothing else is configured.                                                                    |
| `DedicatedLocaleFallbackStrategy::class.':en'` | Falls back to one specific, fixed locale.                                                                                                                     |
| `ModelLocalesFallbackStrategy`                 | Cascades through every locale the model actually has a translation in.                                                                                        |
| `KeyPlaceholderFallbackStrategy`               | No locale fallback; if nothing resolves, returns `"{locale}.{key}"` instead of `null` — handy for spotting missing translations in the UI during development. |
| `NoFallbackStrategy`                           | No fallback at all; returns `null` if the requested locale has no translation.                                                                                |

A strategy can be set application-wide (`fallback_strategy` in the config file), per-model (override `getDefaultTranslationsFallbackStrategy(): FallbackStrategy`), or per-call:

```php
$post->getTranslation('title', 'ar', NoFallbackStrategy::class);
```

Write your own by extending the abstract `FallbackStrategy` class and implementing `fallbackLocales(Model $model, string $key, string $requestedLocale): array` (the locales to try, in order), and optionally `missing(...)` (what to return if every one of them comes up empty — defaults to `null`).

## Casting

Every translatable attribute — literal (whether direct or nested) or wildcard — is fully subject to whatever Eloquent cast its own column declares (`array`, `AsCollection`, `encrypted`, `encrypted:array`, a custom `CastsAttributes` class, etc.). A write runs through the real cast pipeline before its translation is extracted, and a read re-applies the same cast after the translation is merged back in.

> [!WARNING]
> If a column - whether declared translatable itself, or one that nests
> translatable attributes under it - uses an object-returning cast (e.g.
> `AsCollection`, `AsArrayObject`, or any custom `Castable`), mutating it in
> place - `$model->column['key'] = $value;` - is never intercepted.
> The change bypasses translations entirely and is written straight into the raw column.
> Always read the value, mutate a copy, then reassign it:
>
> ```php
> $value = $model->column;
> $value['key'] = 'new value';
> $model->column = $value;
> ```

## Querying

The package transparently joins `model_translations` when a query targets a **literal translatable attribute**, whether direct or nested, and queries its translation for the **current application locale**.

The following query operations are supported out of the box:

- `where()`
- `whereIn()`
- `orderBy()`
- `pluck()`

This also covers builder methods that delegate to these supported operations, such as `firstWhere()`, `orWhere()`, `whereNot()`, `whereNotIn()`, `orWhereIn()`, `orWhereNotIn()`, `orderByDesc()`, and their related variants.

```php
Post::where('title', 'like', '%hello%')
    ->orderBy('title')
    ->get();

Post::whereIn('meta->title', ['Hello', 'Hi'])->get();

Post::pluck('title', 'id');
```

Nested literal attributes use Laravel's JSON selector syntax:

```php
Post::where('meta->title', 'like', '%hello%')->get();
```

> [!IMPORTANT]
> Translation queries require the model's database connection and the `model_translations` table's connection to be the **same connection**. A SQL join cannot span different database connections.

> [!WARNING]
> Wildcard-declared translatables cannot be queried. Their concrete translation keys depend on model instance data and therefore cannot be resolved at query time.

### Custom queries

For a query operation that is not supported, use the [ModelTranslatableQueryBuilder API](#modeltranslatablequerybuilder) to build the query and explicitly join and reference the required translation.

> [!WARNING]
> Do not use custom translation joins with `select()` or other column-selection methods when retrieving model instances. The resulting models can have inconsistent and unpredictable attribute state because the selected translation values can interfere with the model's normal translation interception and attribute loading behavior.

## Disabling Translations

`TranslatableModel::withoutTranslations()` (a facade over the package's `TranslatableModelManager` singleton) runs a callback with translation interception fully suspended — querying, attribute access, and assignment all fall straight through to plain Eloquent behavior, with no translation interception at all.

This is also how you set a translatable column's *placeholder* directly — e.g. writing realistic-looking initial data on creation without it being diverted into the translations table:

```php
$post = TranslatableModel::withoutTranslations(function () {
    return Post::create([
        'title' => 'untitled', // stored as-is, in the title column itself — not a translation
        'meta' => ['author' => 'Ahmad', 'description' => 'no description yet'], // same thing applies to meta.description
    ]);
});
```

Outside `withoutTranslations()`, the same `Post::create([...])` call would instead divert `title` and `meta.description` into `model_translations` for the current locale, leaving the raw column holding whatever it held before (typically `null` on a fresh row).

## API Reference

### `HasTranslations` (model-facing)

| Method                                                        | Returns                  | Description                                                                                                                                                             |
| ------------------------------------------------------------- | ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `getTranslation(key, locale = null, fallbackStrategy = null)` | `?string`                | Get one translation for a locale, applying the specified fallback strategy                                                                                              |
| `getTranslations(key, fallbackStrategy = null)`               | `?array`                 | Get all available translations for one key, applying the specified fallback strategy                                                                                    |
| `setTranslation(key, translation, locale = null)`             | `static`                 | Set one translation; `null` removes it                                                                                                                                  |
| `setTranslations(key, translations)`                          | `static`                 | Set translations for one key across multiple locales                                                                                                                    |
| `removeTranslation(key, locale = null)`                       | `static`                 | Remove one translation for a locale                                                                                                                                     |
| `removeTranslationsForKeys(keys)`                             | `static`                 | Remove the given keys across all locales                                                                                                                                |
| `removeTranslationsForLocales(locales)`                       | `static`                 | Remove the given locales across all keys                                                                                                                                |
| `flushAllTranslations()`                                      | `static`                 | Remove all translations for the model                                                                                                                                   |
| `hasTranslation(key, locale = null)`                          | `bool`                   | Determine whether a translation exists for a key and locale                                                                                                             |
| `getTranslatables()`                                          | `array<string>`          | Get all declared or discovered translatable attribute keys, **without resolving wildcard patterns**                                                                     |
| `getConcreteTranslatables()`                                  | `array<string>`          | Get all declared or discovered translatable attribute keys, resolving wildcard patterns into their concrete positional keys against the current model instance's data.  |
| `resolveNestedConcreteTranslatableAttributes($key)`           | `array<string>`          | Get all nested translatable attributes beneath the given concrete key, expanding wildcard-declared attributes into concrete positional keys based on the instance data. |
| `isTranslatableAttribute(key)`                                | `bool`                   | Determine whether the given key is translatable                                                                                                                         |
| `isNestingTranslatableAttributes(key)`                        | `bool`                   | Determine whether the given key contains translatable attributes beneath it                                                                                             |
| `rememberDynamicTranslatable(key)`                            | `static`                 | Register a key for dynamic translation discovery                                                                                                                        |
| `hasDynamicTranslatables()`                                   | `bool`                   | Whether the translatable attributes should be resolved dynamically.                                                                                                     |
| `loadTranslations(locale)` / `loadAllTranslations()`          | `static`                 | Load translations into the model before they are accessed                                                                                                               |
| `getTranslationsState()`                                      | `ModelTranslationsState` | Get the model's in-memory [translations state](#modeltranslationsstate).                                                                                                |

### `ModelTranslatableQueryBuilder`

Query builder used by translatable models to resolve supported literal translatable attributes to their current-locale translations.

| Method                                                   | Returns  | Description                                                   |
| -------------------------------------------------------- | -------- | ------------------------------------------------------------- |
| `setTranslatableModel(model)`                            | `static` | Set the translatable model whose attributes are being queried |
| `joinTranslation(key, locale = null)`                    | `void`   | Join the translation record for a key and locale              |
| `getQualifiedTranslationValueColumn(key, locale = null)` | `string` | Get the qualified `value` column used by a translation join   |

### `ModelTranslationsRepository`

Database-level API for retrieving and modifying translations.

| Method                                            | Returns                            | Description                                                                                                             |
| ------------------------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `getModelTranslationsForLocale(type, id, locale)` | `array<key, value>`                | Get all translations for a specific model instance in a single locale                                                   |
| `getModelTranslations(type, id)`                  | `array<locale, array<key, value>>` | Get all translations for a specific model instance across all locales                                                   |
| `getModelKeys(type, id = null)`                   | `array<string>`                    | Get the distinct translation keys for a specific model instance, or for all model instances of a type                   |
| `getModelLocales(type, id)`                       | `array<string>`                    | Get the distinct locales that have translations for a specific model instance                                           |
| `upsertModelTranslations(type, id, translations)` | `int`                              | Add or update multiple translations for a specific model instance (`null` values delete the corresponding translations) |
| `deleteModelTranslations(type, id, translations)` | `int`                              | Delete specific translation keys across specified locales for a specific model instance                                 |
| `deleteModelKeys(type, id, keys)`                 | `int`                              | Delete the given translation keys across all locales for a specific model instance                                      |
| `deleteModelLocales(type, id, locales)`           | `int`                              | Delete the given locales across all translation keys for a specific model instance                                      |
| `flushModelTranslations(type, id)`                | `int`                              | Delete all translations for a specific model instance                                                                   |
| `flushLocale(locale)`                             | `int`                              | Delete all translations for a specific locale across every model instance                                               |
| `modelTranslations(type, id)`                     | `Builder`                          | Get a query builder scoped to a specific model instance's translations                                                  |
| `table()`                                         | `Builder`                          | Get an unscoped query builder for the underlying translations table                                                     |

### `ModelTranslationsState`

Per-instance state for reading, staging, and committing translations.

| Method                             | Returns                           | Description                                                             |
| ---------------------------------- | --------------------------------- | ----------------------------------------------------------------------- |
| `load(locale)`                     | `static`                          | Load and cache translations for one locale                              |
| `loadAll()`                        | `static`                          | Load and cache translations for every locale                            |
| `original(key, locale)`            | `?string`                         | Get the originally loaded value, ignoring pending changes               |
| `originals(locale = null)`         | `array`                           | Get originally loaded values, optionally limited to one locale          |
| `get(key, locale)`                 | `?string`                         | Get the current value, including pending changes                        |
| `all()`                            | `array<locale, array<key,value>>` | Get all current translation values                                      |
| `locales()`                        | `array<string>`                   | Get all currently known locales                                         |
| `forKey(key)`                      | `array<locale,value>`             | Get a key's translations across all locales                             |
| `forLocale(locale)`                | `array<key,value>`                | Get all translations for one locale                                     |
| `queuedUpserts()`                  | `array`                           | Get pending translation upserts                                         |
| `queuedDeletes()`                  | `array<locale, array<key>>`       | Get pending key/locale deletions                                        |
| `queuedDeleteKeys()`               | `array<string>`                   | Get pending whole-key deletions                                         |
| `queuedDeleteLocales()`            | `array<string>`                   | Get pending whole-locale deletions                                      |
| `upsert(key, translation, locale)` | `static`                          | Queue a translation for one key and locale                              |
| `delete(key, locale)`              | `static`                          | Queue deletion of one key and locale                                    |
| `deleteKeys(keys)`                 | `static`                          | Queue deletion of one or more keys across all locales                   |
| `deleteLocales(locales)`           | `static`                          | Queue deletion of one or more locales across all keys                   |
| `flushAll()`                       | `static`                          | Queue deletion of all translations                                      |
| `isLoaded(locale)`                 | `bool`                            | Determine whether a locale has been loaded                              |
| `isAllLoaded()`                    | `bool`                            | Determine whether all translations have been loaded                     |
| `isFlushAllQueued()`               | `bool`                            | Determine whether a full flush has been queued                          |
| `has(key, locale)`                 | `bool`                            | Determine whether a translation currently resolves for a key and locale |
| `hasPendingChanges()`              | `bool`                            | Determine whether there are pending changes                             |
| `commit()`                         | `void`                            | Persist all pending changes and clear the queue                         |
| `clear()`                          | `static`                          | Discard pending changes while keeping cached translations               |
| `reset()`                          | `static`                          | Discard both pending changes and cached translations                    |

### `TranslatableModelManager` (via the `TranslatableModel` facade)

| Method                                  | Returns            | Description                                                       |
| --------------------------------------- | ------------------ | ----------------------------------------------------------------- |
| `withoutTranslations(callback)`         | `mixed`            | Run a callback with translation interception temporarily disabled |
| `isTranslationsDisabled()`              | `bool`             | Determine whether translation interception is currently disabled  |
| `connection()`                          | `?string`          | Get the configured translations database connection               |
| `defaultFallbackStrategy()`             | `FallbackStrategy` | Get the application's default fallback strategy                   |
| `shouldFlushTranslationsOnSoftDelete()` | `bool`             | Determine whether translations are flushed on soft-delete         |

## Contributing

If you find any issues or have suggestions for improvements, feel free to open an issue or submit a pull request on the GitHub repository.

## Credits

- Palestine banner and badge by [Safouene1](https://github.com/Safouene1/support-palestine-banner).

## License

**Laravel Translatable Model** is open-sourced software licensed under the [MIT license](LICENSE).
