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

If you're upgrading from a previous version, you may need to run the additional migration to add vector store support:

```bash
php artisan migrate
```

This will add the `vector_store_id` column to the assistants table, which is used to store the ID of the vector store associated with the assistant.

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
        'initial_message' => env('OPENAI_ASSISTANT_INITIAL_MESSAGE', null),
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
  "engine" => "gpt-3.5-turbo-0125"
]);
```

Feel free to modify the assistant's name, instructions, and engine to suit your needs. You probably will create
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
}
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

When you create a new thread, it will automatically create an empty thread in OpenAI. If you have configured an initial message in your config file, it will also be sent to the thread.

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

### Getting the response

Let's be honest - this is what the AI assistant is all about. We want to get the response!
Luckily, it is very easy to do. You can use the `getLastMessage` method of the `Thread` model:

```php
$thread
  ->getLastMessage()
  ->response;
```

How to retrieve all messages? Easy. You can use the  `messages`  relation of the `Thread` model:

```php
$thread
  ->messages()
  ->latest()
  ->get();
```

### Streaming Responses

One of the most powerful features of this package is the ability to stream responses from the assistant in real-time. This creates a more interactive experience for your users, as they can see the assistant's response being generated word by word.

To use streaming, you can use the `runWithStreaming` method on a Thread:

```php
$message = $thread->createMessage([
  "prompt" => "Tell me a story about a brave knight",
  "response_type" => "text"
]);

$thread->runWithStreaming($message, function($chunk, $message, $isDone = false, $error = null) {
    if ($error) {
        // Handle error
        Log::error('Error in streaming: ' . $error->getMessage());
        return;
    }
    
    if ($isDone) {
        // Streaming is complete
        echo "Streaming completed!";
        return;
    }
    
    // Process each chunk of the response
    echo $chunk;
    
    // You can also broadcast this chunk to a websocket for real-time updates
    broadcast(new MessageChunkReceived($chunk, $message->thread_id));
});
```

The streaming callback receives four parameters:
- `$chunk`: The text chunk from the assistant (null when streaming is complete)
- `$message`: The message object being processed
- `$isDone`: Boolean indicating if streaming is complete
- `$error`: Any error that occurred during streaming (null if no error)

This approach is perfect for creating chat interfaces where users can see the assistant typing in real-time.

### Using Tools with Assistants

OpenAI Assistants can use tools to perform actions or retrieve information. This package provides built-in support for handling tool calls during conversations.

When an assistant requires a tool action, the package will automatically handle the interaction:

```php
$thread->runWithStreaming($message, function($chunk, $message, $isDone = false, $error = null) {
    // Same callback as before
});
```

The default implementation provides a simple response for tool calls, but you can customize this behavior by extending the Thread class or implementing your own tool handlers.

For more advanced tool handling, you can create a custom implementation:

```php
// Custom tool handler example
class MyToolHandler
{
    public function handleToolCall($toolCall)
    {
        $function = $toolCall->function->name ?? '';
        $arguments = json_decode($toolCall->function->arguments ?? '{}', true);
        
        switch ($function) {
            case 'get_weather':
                return ['temperature' => 22, 'condition' => 'sunny'];
            case 'search_database':
                return $this->searchDatabase($arguments['query']);
            default:
                return ['error' => 'Unknown function'];
        }
    }
    
    private function searchDatabase($query)
    {
        // Implementation of database search
        return ['results' => [/* search results */]];
    }
}
```

Then you can use this handler in your application:

```php
$toolHandler = new MyToolHandler();

// When processing tool calls
$toolOutputs = [];
foreach ($toolCalls as $toolCall) {
    $result = $toolHandler->handleToolCall($toolCall);
    $toolOutputs[] = [
        'tool_call_id' => $toolCall->id,
        'output' => json_encode($result),
    ];
}
```

### Creating Threads Programmatically

You can create threads programmatically using the static `createWithOpenAI` method:

```php
$thread = Thread::createWithOpenAI($assistant, [
    'user_id' => $user->id,
    'context' => 'customer_support'
]);
```

This method creates both a local thread record and a thread in OpenAI, linking them together. The second parameter allows you to pass metadata that will be stored with the thread.

### Scoping the conversation

Your assistant will be more helpful if it knows the context of the conversation. You can feed it with text or json, which the assistant
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

This will upload the file to OpenAI and attach it to the assistant. The file will be available for all threads using this assistant.

If you need to attach multiple files at once, you can use the `attachFiles` method:

```php
$result = $thread->attachFiles([$path1, $path2, $path3]);
// $result contains 'files' and 'errors' arrays
```

### Managing Files in Threads

You can manage files directly in threads using several methods:

```php
// Add a file to a thread
$fileId = $thread->addFile($openaiFileId);

// Get all files in a thread
$files = $thread->getFiles();

// Remove a file from a thread
$success = $thread->removeFile($fileId);
```

These methods interact directly with the OpenAI API to manage files associated with the thread.

### Managing assistant files

Sometimes you may want to reset the assistant's knowledge by removing all files. You can do this with the `resetFiles` method on the assistant:

```php
$assistant->resetFiles();
```

This will:
1. Remove all files from the assistant in OpenAI by setting an empty `file_ids` array
2. Attempt to delete each file from OpenAI's storage (with error handling for each file)
3. Remove all file records from your database
4. Return the assistant instance for method chaining

This is useful when you want to completely refresh the assistant's knowledge base:

```php
// Reset and add new files in one chain
$assistant->resetFiles()
    ->attachFiles([$newPath1, $newPath2]);
```

You can also add files directly to the assistant without going through a thread:

```php
$file = $assistant->attachFile($path);
```

When you don't specify a thread, the system will:
1. First try to find an existing thread for this assistant
2. If no thread exists, it will create a "system" thread automatically
3. This ensures that the database constraint for `thread_id` is satisfied

### Thread Management

When adding files directly to an assistant without specifying a thread, the system needs to handle the database constraint for `thread_id`. Here's how it works:

1. First, the system tries to find an existing thread for this assistant
2. If no thread exists, it creates a "system" thread automatically with:
   ```php
   $thread = $assistant->threads()->create([
       'uuid' => uniqid('system_'),
       'model_id' => 0,
       'model_type' => 'System',
   ]);
   ```
3. This ensures that the database constraint for `thread_id` is satisfied
4. The system thread is used only for file management and doesn't affect your regular conversations

This approach allows you to manage files directly through the assistant without worrying about thread management, while still maintaining database integrity.

If you need to remove a specific file from the assistant:

```php
$assistant->removeFile($fileId);
```

This will remove the file from both OpenAI and your database. The method handles errors gracefully:
- If the file doesn't exist in OpenAI, it will still be removed from your database
- If there's an error communicating with OpenAI, the method will log the error and return false
- On success, the method returns true

To get a list of files currently attached to the assistant in OpenAI:

```php
$files = $assistant->getOpenAIFiles();
```

The returned array contains detailed information about each file.

### Updating Assistant Knowledge

When you need to update the assistant's knowledge base with new information, you can use the `updateKnowledge` method. This method effectively creates a new vector store by removing all existing files and adding new ones:

```php
$result = $assistant->updateKnowledge([$path1, $path2, $path3]);
```

The method returns detailed information about the operation:

```php
[
    'success' => true,
    'message' => 'Wiedza asystenta została zaktualizowana.',
    'files_added' => 2,
    'vector_store_id' => 'vs_abc123',
    'errors' => [
        [
            'path' => '/path/to/file3.pdf',
            'message' => 'File not found'
        ]
    ]
]
```

You can also specify a thread to associate the files with:

```php
$result = $assistant->updateKnowledge([$path1, $path2, $path3], $threadId);
```

This method is particularly useful when:
- You have new versions of documents that replace old ones
- You need to completely refresh the assistant's knowledge base
- You want to ensure the vector store is rebuilt from scratch

Under the hood, this method:
1. Uploads new files to OpenAI
2. Checks if a vector store already exists for this assistant and deletes it if found
3. Creates a new vector store and associates it with the assistant
4. Adds the uploaded files to the vector store
5. Updates the assistant to use the new vector store and files
6. Saves file records in your database

#### About Vector Stores in OpenAI

When you add files to a vector store, OpenAI automatically:
- Parses the content of the files
- Chunks the content into smaller pieces
- Creates embeddings for each chunk
- Builds a vector store for efficient semantic search

This process happens transparently, but unlike the previous approach, we now explicitly manage the vector store to ensure it's properly created and associated with the assistant.

You can retrieve information about the assistant's vector store using the `getVectorStore` method:

```php
$vectorStore = $assistant->getVectorStore();
```

The returned array contains detailed information about the vector store:

```php
[
    'id' => 'vs_abc123',
    'name' => 'Vector store for assistant My Assistant',
    'created_at' => '2023-05-15 10:30:45',
    'file_count' => 2,
    'files' => [
        // Array of file objects
    ]
]
```

If no vector store is found for the assistant, the method returns `null`.

This approach allows you to handle errors gracefully without stopping the entire process.

If you already have a vector store created in OpenAI and want to link it to your assistant, you can use the `linkVectorStore` method:

```php
$success = $assistant->linkVectorStore('vs_abc123');
```

This method will:
1. Check if the vector store exists
2. Update the assistant to use the specified vector store
3. Save the vector store ID in your database

This is useful when you've created a vector store manually in the OpenAI playground or through another method and want to use it with your assistant.

This approach allows you to handle errors gracefully without stopping the entire process.

If you need to link multiple vector stores to your assistant, you can use the `linkMultipleVectorStores` method:

```php
$success = $assistant->linkMultipleVectorStores(['vs_abc123', 'vs_def456']);
```

This method will check if all vector stores exist and link only the valid ones to your assistant.

You can check the status of your assistant's vector store configuration using the `checkVectorStoreStatus` method:

```php
$status = $assistant->checkVectorStoreStatus();
```

This method returns detailed information about the vector store configuration:

```php
[
    'status' => 'success',
    'has_retrieval_tool' => true,
    'has_vector_store_id' => true,
    'is_linked' => true,
    'vector_store_id' => 'vs_abc123',
    'linked_vector_store_ids' => ['vs_abc123', 'vs_def456'],
    'vector_store' => [
        'id' => 'vs_abc123',
        'name' => 'Vector store for assistant My Assistant',
    ],
]
```

You can also search your assistant's vector store using the `searchVectorStore` method:

```php
$results = $assistant->searchVectorStore('What is the capital of France?');
```

This method uses the assistant to search the vector store and returns the results:

```php
[
    'query' => 'What is the capital of France?',
    'vector_store_id' => 'vs_abc123',
    'results' => [
        // Array of messages from the assistant
    ],
]
```

### Events and Websockets Integration

This package fires events that you can use to integrate with Laravel Echo and websockets for real-time updates:

```php
// In your EventServiceProvider
protected $listen = [
    'DaSie\Openaiassistant\Events\AssistantUpdatedEvent' => [
        'App\Listeners\AssistantUpdatedListener',
    ],
    'App\Events\MessageChunkReceived' => [
        'App\Listeners\MessageChunkListener',
    ],
];
```

You can create a custom event for streaming chunks:

```php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageChunkReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chunk;
    public $threadId;

    public function __construct($chunk, $threadId)
    {
        $this->chunk = $chunk;
        $this->threadId = $threadId;
    }

    public function broadcastOn()
    {
        return new Channel('thread.' . $this->threadId);
    }
}
```

Then in your frontend, you can listen for these events:

```javascript
Echo.channel(`thread.${threadId}`)
    .listen('MessageChunkReceived', (e) => {
        // Append the chunk to your chat interface
        appendMessageChunk(e.chunk);
    });
```

This creates a seamless real-time chat experience with the AI assistant.

## License
