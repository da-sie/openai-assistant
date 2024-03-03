<?php

namespace DaSie\Openaiassistant\Providers;

use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
