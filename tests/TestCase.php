<?php

namespace Tests;

namespace DaSie\Openaiassistant\Tests;

use DaSie\Openaiassistant\OpenaiAssistantServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Ray;

class TestCase extends BaseTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ładowanie zmiennych środowiskowych z .env.testing
        if (file_exists(__DIR__.'/../.env.testing')) {
            (Dotenv::createImmutable(__DIR__.'/../', '.env.testing'))->load();
            var_dump('OPENAI_API_KEY from env: ' . env('OPENAI_API_KEY'));
        }
        
        // Ustawienia konfiguracyjne dla testów
        Config::set('openai-assistant.table.assistants', 'ai_assistants');
        Config::set('openai-assistant.table.files', 'ai_files');
        Config::set('openai-assistant.table.threads', 'ai_threads');
        Config::set('openai-assistant.table.messages', 'ai_messages');
        var_dump('OPENAI_API_KEY from config: ' . config('openai.api_key'));
        
        // Uruchom migracje
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
    
    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Konfiguracja bazy danych
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        
        // Konfiguracja pakietu
        $app['config']->set('openai-assistant.table.assistants', 'ai_assistants');
        $app['config']->set('openai-assistant.table.files', 'ai_files');
        $app['config']->set('openai-assistant.table.threads', 'ai_threads');
        $app['config']->set('openai-assistant.table.messages', 'ai_messages');
        
        // Konfiguracja OpenAI
        $app['config']->set('openai.api_key', env('OPENAI_API_KEY'));
    }
    
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            OpenaiAssistantServiceProvider::class,
        ];
    }
    
    /**
     * Teardown the test environment.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        
        if (class_exists(Mockery::class)) {
            if ($container = Mockery::getContainer()) {
                $this->addToAssertionCount($container->mockery_getExpectationCount());
            }
            
            Mockery::close();
        }
    }
} 