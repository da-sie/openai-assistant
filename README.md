# Open AI Model assistant

[![Latest Version on Packagist](https://img.shields.io/packagist/v/da-sie/openai-assistant.svg?style=flat-square)](https://packagist.org/packages/da-sie/openai-assistant)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/da-sie/openai-assistant/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/da-sie/openai-assistant/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/da-sie/openai-assistant/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/da-sie/openai-assistant/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/da-sie/openai-assistant.svg?style=flat-square)](https://packagist.org/packages/da-sie/openai-assistant)

This package acts as a specialized wrapper for the OpenAI API, tailored specifically for Laravel, to facilitate the seamless integration of AI assistants into Laravel projects. It simplifies the creation and management of AI conversations, which can be effortlessly linked to Laravel models. Additionally, it is equipped to generate events for each sent and received message, streamlining the logging and monitoring of interactions.

It also offers conversation scoping capabilities, allowing you to refine the context and direction of interactions based on specific needs, using either text or JSON to limit the conversation's scope. This functionality enhances the ability to customize and fine-tune AI conversations within Laravel applications, ensuring they are more pertinent and focused.

Think it sounds complex? On the contrary, it introduces a world of exciting possibilities! :)

## Installation

You can install the package via composer:

```bash
composer require da-sie/openai-assistant
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="openai-assistant-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="openai-assistant-config"
```

This is the contents of the published config file:

```php
return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'api_org' => env('OPENAI_ORGANIZATION'),
    ],
    'assistant' => [
        'engine' => env('OPENAI_ASSISTANT_ENGINE', 'gpt-3.5-turbo-0125'),
    ],
    'table' => [
        'assistants' => 'ai_assistants',
        'messages' => 'ai_messages',
    ],
];
```

## Usage

```php
//@todo:    Add usage instructions :)
```

## Credits

- [Przemek Jaskulski](https://github.com/da-sie)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
