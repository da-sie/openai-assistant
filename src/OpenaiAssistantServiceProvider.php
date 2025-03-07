<?php

namespace DaSie\Openaiassistant;

use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use DaSie\Openaiassistant\Commands\OpenaiAssistantCommand;

class OpenaiAssistantServiceProvider extends PackageServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        // Publikowanie konfiguracji
        $this->publishes([
            __DIR__.'/../config/openai-assistant.php' => config_path('openai-assistant.php'),
        ], 'openai-assistant-config');

        // Publikowanie migracji
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'openai-assistant-migrations');

        // Ładowanie migracji
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Łączenie konfiguracji
        $this->mergeConfigFrom(
            __DIR__.'/../config/openai-assistant.php', 'openai-assistant'
        );
    }

    /**
     * Configure the package.
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('openai-assistant')
            ->hasConfigFile()
            ->hasMigrations([
                'create_openai_assistant_table',
                'userable_openai_assistant_table',
            ]);
            
        if (class_exists(OpenaiAssistantCommand::class)) {
            $package->hasCommand(OpenaiAssistantCommand::class);
        }
    }

    /**
     * Bootstrap the application services.
     */
    public function packageBooted()
    {
        // Rejestracja EventServiceProvider
        if (class_exists('\DaSie\Openaiassistant\Providers\EventServiceProvider')) {
            $this->app->register(\DaSie\Openaiassistant\Providers\EventServiceProvider::class);
        }
    }
}
