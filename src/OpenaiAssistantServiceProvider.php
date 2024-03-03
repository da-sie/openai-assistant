<?php

namespace DaSie\Openaiassistant;

use DaSie\Openaiassistant\Commands\OpenaiAssistantCommand;
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
            ->hasMigration('create_openai_assistant_table')
            ->hasCommand(OpenaiAssistantCommand::class);
    }


    public function packageBooted()
    {
        $this->app->register(\DaSie\Openaiassistant\Providers\EventServiceProvider::class);
    }

}
