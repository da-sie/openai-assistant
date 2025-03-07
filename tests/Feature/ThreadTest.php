<?php

use DaSie\Openaiassistant\Models\Thread;
use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Message;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mockujemy klienta OpenAI, aby nie wykonywać rzeczywistych zapytań
    Http::fake([
        'api.openai.com/v1/threads' => Http::response([
            'id' => 'thread_test123',
            'object' => 'thread',
            'created_at' => time(),
        ], 200),
        'api.openai.com/v1/threads/*/messages' => Http::response([
            'id' => 'msg_test123',
            'object' => 'thread.message',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => [
                        'value' => 'Test message',
                        'annotations' => []
                    ]
                ]
            ],
        ], 200),
        'api.openai.com/v1/threads/*/runs' => Http::response([
            'id' => 'run_test123',
            'object' => 'thread.run',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'assistant_id' => 'asst_test123',
            'status' => 'queued',
        ], 200),
        'api.openai.com/v1/threads/*/runs/*/submit_tool_outputs' => Http::response([
            'id' => 'run_test123',
            'object' => 'thread.run',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'assistant_id' => 'asst_test123',
            'status' => 'in_progress',
        ], 200),
        'api.openai.com/v1/threads/*/messages/files' => Http::response([
            'data' => [
                [
                    'id' => 'file-test123',
                    'object' => 'thread.message.file',
                    'created_at' => time(),
                ]
            ]
        ], 200),
    ]);
});

test('thread has correct relations', function () {
    $thread = new Thread();
    
    expect($thread->assistant())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($thread->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('createMessage creates message in OpenAI and database', function () {
    // Tworzymy mock dla Thread
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->id = 1;
    $thread->openai_thread_id = 'thread_test123';
    
    // Wywołujemy metodę
    $message = $thread->createMessage('Test message');
    
    // Sprawdzamy, czy wiadomość została utworzona
    expect($message)
        ->toBeInstanceOf(Message::class)
        ->and($message->thread_id)->toBe(1)
        ->and($message->content)->toBe('Test message')
        ->and($message->role)->toBe('user')
        ->and($message->openai_message_id)->toBe('msg_test123');
});

test('createWithOpenAI creates thread in OpenAI and database', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    
    // Wywołujemy metodę
    $thread = Thread::createWithOpenAI($assistant);
    
    // Sprawdzamy, czy wątek został utworzony
    expect($thread)
        ->toBeInstanceOf(Thread::class)
        ->and($thread->assistant_id)->toBe(1)
        ->and($thread->openai_thread_id)->toBe('thread_test123');
});

test('run executes assistant and returns message', function () {
    // Tworzymy mocki dla Thread, Assistant i Message
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->openai_assistant_id = 'asst_test123';
    
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->openai_thread_id = 'thread_test123';
    $thread->shouldReceive('relationLoaded')->with('assistant')->andReturn(true);
    $thread->assistant = $assistant;
    
    $message = Mockery::mock(Message::class)->makePartial();
    $message->openai_message_id = 'msg_test123';
    $message->shouldReceive('saveQuietly')->andReturn(true);
    
    // Mockujemy odpowiedź z OpenAI dla pobierania wiadomości
    Http::fake([
        'api.openai.com/v1/threads/*/messages' => Http::response([
            'data' => [
                [
                    'id' => 'msg_response123',
                    'object' => 'thread.message',
                    'created_at' => time(),
                    'thread_id' => 'thread_test123',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => [
                                'value' => 'Test response',
                                'annotations' => []
                            ]
                        ]
                    ],
                ]
            ]
        ], 200),
        'api.openai.com/v1/threads/*/runs/*' => Http::response([
            'id' => 'run_test123',
            'object' => 'thread.run',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'assistant_id' => 'asst_test123',
            'status' => 'completed',
        ], 200),
    ]);
    
    // Wywołujemy metodę
    $result = $thread->run($message);
    
    // Sprawdzamy, czy wiadomość została zaktualizowana
    expect($result)
        ->toBe($message)
        ->and($message->response)->toBe('Test response')
        ->and($message->run_status)->toBe('completed');
});

test('addFile adds file to thread', function () {
    // Tworzymy mock dla Thread
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->openai_thread_id = 'thread_test123';
    
    // Wywołujemy metodę
    $fileId = $thread->addFile('file-test123');
    
    // Sprawdzamy, czy plik został dodany
    expect($fileId)->toBe('file-test123');
});

test('getFiles returns files from thread', function () {
    // Tworzymy mock dla Thread
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->openai_thread_id = 'thread_test123';
    
    // Wywołujemy metodę
    $files = $thread->getFiles();
    
    // Sprawdzamy, czy pliki zostały pobrane
    expect($files)
        ->toBeArray()
        ->and($files[0]->id)->toBe('file-test123');
});

test('removeFile removes file from thread', function () {
    // Tworzymy mock dla Thread
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->openai_thread_id = 'thread_test123';
    
    // Wywołujemy metodę
    $result = $thread->removeFile('file-test123');
    
    // Sprawdzamy, czy plik został usunięty
    expect($result)->toBeTrue();
}); 