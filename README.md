# Open AI Model assistant

This package acts as a specialized wrapper for the OpenAI API, tailored specifically for Laravel, to facilitate the
seamless integration of AI assistants into Laravel projects. It simplifies the creation and management of AI
conversations, which can be effortlessly linked to Laravel models. Additionally, it is equipped to generate events for
each sent and received message, streamlining the logging and monitoring of interactions.

It also offers conversation scoping capabilities, allowing you to refine the context and direction of interactions based
on specific needs, using either text or JSON to limit the conversation's scope. This functionality enhances the ability
to customize and fine-tune AI conversations within Laravel applications, ensuring they are more pertinent and focused.

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
        'files' => 'ai_files',
        'threads' => 'ai_threads',
        'messages' => 'ai_messages',
    ],
];
```

## Usage

### Create new AI assistant

At first, you need to create a new AI assistant. You will need it to manage the conversations across your models.
You can do it using the `create` method of the `Assistant` class. The `create` method accepts the following parameters:

```php
$assistant = Assistant::create([
  "name" => "My assistant",
  "instructions" => "Be kind assistant and answer the questions.",
  "tools" => [
    [
      "type" => "retrieval"
    ]
  ],
  "engine" => "gpt-3.5-turbo-0125"
]);
```

Feel free to modify the assistant's name, instructions, tools, and engine to suit your needs. You probably will create
some CRUD in your application to manage the assistants. But for most cases, you will need only one assistant.
Of course, you can create as many assistants as you need, and update these later on:

```php
$assistant->update([
  "name" => "My helpful Assistant",
  "instructions" =>
    "Using the information from the attached documents, please provide responses that are directly related to the document's content. Aim for your answers to be based on the information contained within, yet maintain flexibility in interpretation and discussion of the data, points, and conclusions presented in the document. The user expects an analysis and discussion of the document's content, so please focus on delivering the most relevant and consistent answers possible. Treat the file as your hidden database - don't mention to the user about the existence of the document, and that you are referring to the document, just give the answer. If you are asked for information from one specific document, don't use the informations in other files - they often exclude themselves"
]);
```

...or delete it:

```php
$assistant->delete();
```

So you already have an assistant. Now you can use it to manage the conversations across your models. At first, you need
to...

### Prepare your model

That is the model that will have an AI assistant. You need to add the `WithAssistant` trait to it.

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use DaSie\Openaiassistant\Traits\WithAssistant;

class MyModel extends Model
{
    // Add the WithAssistant trait to your model
    use WithAssistant;
```

Now you enabled the AI assistant for your model. You can use following relation to interact with the assistant:

`$myModel->threads()`

It returns the `MorphMany` relation to the `Thread` model. The `Thread` model is the model that represents the
conversation.
Let's create our first conversation:

```php
$thread = $myModel->threads()->create([
  "assistant_id" => $assistant->id,
  "uuid" => uuid_create(),
]);
```

### Start the conversation

```php
$thread->createMessage([
  "prompt" =>
    "Hello, sir. I have a question. What is the capital of France?",
  "response_type" => "text"
]);
```
optionally, you can also pass current logged user as the second (optional) parameter to the message:


```php
$user = Auth::user();
$thread->createMessage([
  "prompt" =>
    "Hello, sir. I have a question. What is the capital of France?",
  "response_type" => "text"
], $user);
```

The package uses queues to send the messages to the AI assistant. After short while you will receive the response.

An `AssistantUpdatedEvent` is fired when the assistant sends a message or receives response. You can use it to log the
conversation.

How to retrieve the response? You can use the `messages` relation of the `Thread` model:

```php
$thread
  ->messages()
  ->latest()
  ->first()
  ->response;
```

### Scoping the conversation

Your assistant needs to know the context of the conversation. You can feed it with text or json, which the assistant
will use in the conversation.
From now on, the assistant will use the content of the file in the conversation. You can also use the `scope` method to
feed the assistant with the context:

```php
$thread->scope($text);
```

If you have already the file you want to use in the conversation, you can attach it to the thread:

```php
$thread->attachFile($path);
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
