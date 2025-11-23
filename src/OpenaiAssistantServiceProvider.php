<?php

namespace DaSie\Openaiassistant;

use DaSie\Openaiassistant\Commands\OpenaiAssistantCommand;
use DaSie\Openaiassistant\Commands\SyncToolsCommand;
use DaSie\Openaiassistant\Services\ToolCallHandler;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OpenaiAssistantServiceProvider extends PackageServiceProvider
{
    /**
     * Register the application services.
     */
    public function register()
    {
        parent::register();

        // Łączenie konfiguracji
        $this->mergeConfigFrom(
            __DIR__.'/../config/openai-assistant.php', 'openai-assistant'
        );

        // Register ToolCallHandler as singleton
        $this->app->singleton(ToolCallHandler::class, function ($app) {
            return new ToolCallHandler();
        });
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
            ])
            ->hasCommands([
                OpenaiAssistantCommand::class,
                SyncToolsCommand::class,
            ]);
    }

    /**
     * Bootstrap the application services.
     */
    public function packageBooted()
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

        // Rejestracja EventServiceProvider
        if (class_exists('\DaSie\Openaiassistant\Providers\EventServiceProvider')) {
            $this->app->register(\DaSie\Openaiassistant\Providers\EventServiceProvider::class);
        }
    }
}
