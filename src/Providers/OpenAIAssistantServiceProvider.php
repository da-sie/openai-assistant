<?php

namespace DaSie\Openaiassistant\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\Conversions\Commands\RegenerateCommand;
use Spatie\MediaLibrary\MediaCollections\Commands\CleanCommand;
use Spatie\MediaLibrary\MediaCollections\Commands\ClearCommand;

class OpenAIAssistantServiceProvider extends PackageServiceProvider
{

    public function configurePackage(Package $package): void
    {
        $package
            ->name('openai-assistant')
            ->hasConfigFile('openai-assistant')
            ->hasMigration('create_assistant_table')
            ->publishesServiceProvider('EventServiceProvider')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->copyAndRegisterServiceProviderInApp()
                    ->askToStarRepoOnGitHub('da-sie/openai-assistant');
            });
    }

    public function packageRegistered()
    {
        $this->app->register(EventServiceProvider::class);
    }
}
