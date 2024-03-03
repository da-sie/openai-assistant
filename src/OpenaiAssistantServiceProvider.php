<?php

namespace DaSie\OpenaiAssistant;

use DaSie\OpenaiAssistant\Commands\OpenaiAssistantCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OpenaiAssistantServiceProvider extends PackageServiceProvider
{
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
            ->hasViews()
            ->hasMigration('create_openai-assistant_table')
            ->hasCommand(OpenaiAssistantCommand::class);
    }
}