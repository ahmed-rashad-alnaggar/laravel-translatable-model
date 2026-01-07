# Laravel Translatable Model

![I Stand With Palestine Badge](./arts/PalestineBadge.svg)

![I Stand With Palestine Banner](./arts/PalestineBanner.svg)

[![Latest Stable Version](https://img.shields.io/packagist/v/alnaggar/laravel-translatable-model)](https://packagist.org/packages/alnaggar/laravel-translatable-model)
[![Total Downloads](https://img.shields.io/packagist/dt/alnaggar/laravel-translatable-model)](https://packagist.org/packages/alnaggar/laravel-translatable-model)
[![License](https://img.shields.io/packagist/l/alnaggar/laravel-translatable-model)](https://packagist.org/packages/alnaggar/laravel-translatable-model)

A small package that stores model attribute translations in a separate database table and provide a simple trait-based API to set/get translations per-locale, including support for nested (dot-notated) keys and [dynamic discovery](#dynamic-discovery).

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Migration](#migration)
- [Usage](#usage)
- [Dynamic Discovery](#dynamic-discovery)
- [Implementation Notes](#implementation-notes)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Requirements

- PHP 7.3+
- Laravel 8+

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

The published config file is `config/translatable-model.php` and exposes:

- `connection` (`string`|`null`): Database connection to use for the translations table. `null` uses the app default connection.
- `fallback_behavior` (`string` | `bool` | `null`): Controls how missing translations are handled **when no explicit fallback locale can be provided**, such as when accessing translatable attributes via:
  - `$model->attribute`
  - `$model['attribute']`
  - `$model->attributesToArray()`

  Supported values:
  - `string` (locale): Fallback to the specified locale.
  - `true` or `null`: Fallback to the application fallback locale.
  - `false`: Do not fallback to any locale (return `null`).

- `flush_translations_on_soft_delete` (`bool`): When `true`, translations will be flushed when a model is soft-deleted. When `false` (default), translations are only flushed on a force-delete.

## Migration

The package publishes a migration that creates the `model_translations` table with the following columns:

- `translatable_type` (string)
- `translatable_id` (string) — supports numeric or string IDs
- `locale` (string)
- `key` (string) — attribute name (supports dot notation for nested values)
- `value` (text, nullable)
- `created_at`, `updated_at`

The table has a composite primary key on `translatable_type`, `translatable_id`, `locale`, `key`.

## Usage

Add the `HasTranslations` trait to any Eloquent model and list translatable attributes.

```php
use Alnaggar\TranslatableModel\HasTranslations;

class Post extends Model
{
    use HasTranslations;

    // flat or dot-notated keys
    protected $translatables = [
        'title',
        'body',
        'meta.title',
        'meta.description',
    ];
}
```

### Get a translation

```php
$titleAr = $post->getTranslation(
    key: 'title',
    locale: 'ar', // null for current locale
    fallback: 'en' // null for app fallback locale, false to return null when missing
);

// retrieves the translation in the current locale, 
// using the configured (config/translatable-model.php) fallback behavior
$titleAr = $post->title;
$titleAr = $post['title'];
```

### Set translation(s)

```php
$post->setTranslation(
    key: 'title',
    value: 'Hello world',
    locale: 'en' // null for current locale
);

$post->setTranslation(
    key: 'title', 
    value: ['en' => 'Hello', 'fr' => 'Bonjour']
);

// Laravel-style assignment for current locale
$post->title = 'Bonjour à tous';

// Translations are upserted when the model is saved
$post->save();
```

### Remove a translation

```php
$post->removeTranslation(
    key: 'meta.description', 
    locale: 'fr' // null for current locale
);

$post->save();
```

### Flush translations

```php
// remove all French translations
$post->flushTranslations('fr'); 

// remove all translations for the model
$post->flushTranslations(null); 

$post->save();
```

### Nested attributes

When a translatable key targets nested data (dot notation), the trait will inject translations into the parent attribute when reading:

```php
// Assuming 'meta.description' is translatable
$post->meta; // => ['author' => 'Ahmad', 'description' => 'Translated value']
```

You can set nested attributes in bulk and the trait will persist translatable parts:

```php
$post->meta = [
    'author' => 'Ahmad',
    'description' => 'A translations management project',
];

$post->save();
```

### Persisting behavior

All translation operations are queued on the model instance and persisted when the model **is saved**.

**Deleting the model flushes all its translations automatically.** For models using SoftDeletes, by default translations are flushed only when the model is force deleted; to flush on soft delete set `flush_translations_on_soft_delete` to `true` in the package config.

### Checking translation existence

```php
if ($post->hasTranslation('content', 'ar')) {
    // Arabic translation exists
}
```

### Dynamic discovery

Defining `translatables` is optional. If your model does not declare translatable keys, the trait will automatically discover existing translation keys stored for the model (for example, seeded or pre-stored translations). This is useful for models with dynamic or varied structures (e.g. a `Setting` model).

Example seeder that demonstrates dynamic discovery:

```php
use App\Models\Setting;
use Alnaggar\TranslatableModel\HasTranslations;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run()
    {
        $generalSettings = Setting::create([
            'key' => 'general',
            'value' => [
                'app_name' => null,
                'app_timezone' => config('app.timezone'),
            ],
        ]);

        $generalSettings->setTranslation('value.app_name', 'My App', 'en');

        $generalSettings->save();
    }
}
```

## Implementation notes

- The trait defers DB writes: translations are cached on the model and upserted/deleted when the model is saved.
- `null` translation values in an upsert are interpreted as removals for that key/locale.
- `$model->attributesToArray()` — will include translatable attributes (with nested values injected).
- **All translatable attribute columns MUST be nullable at the database level.**
  - The package sets these columns to `null` because their actual values are resolved dynamically from the translations store.
  - Nested translatable attributes are also persisted as `null`.

## Contributing

If you find any issues or have suggestions for improvements, feel free to open an issue or submit a pull request on the GitHub repository.

## Credits

- Palestine banner and badge by [Safouene1](https://github.com/Safouene1/support-palestine-banner).

## License

**Laravel Translatable Model** is open-sourced software licensed under the [MIT license](LICENSE).
