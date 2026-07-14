<?php

namespace Alnaggar\TranslatableModel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class TranslatableModelServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * 
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ModelTranslationsRepository::class, static function (Container $app): ModelTranslationsRepository {
            return new ModelTranslationsRepository(
                $app->make('db')->connection($app->make('config')->get('translatable-model.connection'))
            );
        });

        $this->mergeConfigFrom(__DIR__.'/../config/translatable-model.php', 'translatable-model');
    }

    /**
     * Bootstrap any application services.
     * 
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/translatable-model.php' => config_path('translatable-model.php')
        ], 'translatable-model-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations')
        ], 'translatable-model-migrations');
    }
}
